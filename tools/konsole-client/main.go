// BetterPMMP-Konsole: a small Windows program with a menu to run console commands
// on a BetterPMMP server over RCON while you play.
package main

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"
)

//go:embed ui.html
var uiHTML []byte

const uiPort = 19150

// SavedServer is a server this PC was paired with (or logged into with a password).
type SavedServer struct {
	Host   string `json:"host"`
	Port   int    `json:"port"`
	Login  string `json:"login"`  // "token:..." from pairing, or the password if "remember"
	Player string `json:"player"` // your Minecraft name on that server (from pairing)
}

type Config struct {
	Servers []SavedServer `json:"servers"`
	Current int           `json:"current"`
	Buttons []UserButton  `json:"buttons"`
	History []string      `json:"history"`
}

type UserButton struct {
	Label   string `json:"label"`
	Command string `json:"command"`
}

var (
	rcon     Rcon
	cfgMu    sync.Mutex
	cfg      = Config{Current: -1}
	lastPing = time.Now()
	pingMu   sync.Mutex
)

func configPath() string {
	dir, err := os.UserConfigDir()
	if err != nil {
		dir = "."
	}
	return filepath.Join(dir, "BetterPMMP-Konsole", "config.json")
}

func loadConfig() {
	if data, err := os.ReadFile(configPath()); err == nil {
		json.Unmarshal(data, &cfg)
	}
	if cfg.Current >= len(cfg.Servers) {
		cfg.Current = -1
	}
}

func saveConfig() {
	data, _ := json.MarshalIndent(cfg, "", "  ")
	os.MkdirAll(filepath.Dir(configPath()), 0o700)
	os.WriteFile(configPath(), data, 0o600)
}

// parseReply reads "token:..." and "player:..." lines from the login answer.
func parseReply(reply string) (token, player string) {
	for _, line := range strings.Split(reply, "\n") {
		line = strings.TrimSpace(line)
		if v, ok := strings.CutPrefix(line, "token:"); ok {
			token = v
		} else if v, ok := strings.CutPrefix(line, "player:"); ok {
			player = v
		}
	}
	return
}

func findServer(host string, port int) int {
	for i, s := range cfg.Servers {
		if strings.EqualFold(s.Host, host) && s.Port == port {
			return i
		}
	}
	return -1
}

// publicState is what the window sees (no tokens or passwords).
func publicState() map[string]any {
	servers := []map[string]any{}
	for _, s := range cfg.Servers {
		servers = append(servers, map[string]any{"host": s.Host, "port": s.Port, "player": s.Player})
	}
	player := ""
	if cfg.Current >= 0 && cfg.Current < len(cfg.Servers) {
		player = cfg.Servers[cfg.Current].Player
	}
	return map[string]any{
		"servers": servers, "current": cfg.Current, "player": player,
		"buttons": cfg.Buttons, "history": cfg.History, "connected": rcon.Connected(),
	}
}

func useServer(i int) error {
	if i < 0 || i >= len(cfg.Servers) {
		return fmt.Errorf("unbekannter Server")
	}
	s := cfg.Servers[i]
	reply, err := rcon.Connect(s.Host, s.Port, s.Login)
	if err != nil {
		return err
	}
	if _, p := parseReply(reply); p != "" {
		cfg.Servers[i].Player = p
	}
	cfg.Current = i
	saveConfig()
	return nil
}

// autodetect connects to the saved server where your player is online right now.
func autodetect() (int, bool) {
	for i, s := range cfg.Servers {
		if s.Player == "" {
			continue
		}
		if _, err := rcon.Connect(s.Host, s.Port, s.Login); err != nil {
			continue
		}
		out, err := rcon.Exec("list")
		if err == nil && playerOnline(out, s.Player) {
			cfg.Current = i
			saveConfig()
			return i, true
		}
	}
	rcon.Close()
	return -1, false
}

func playerOnline(listOutput, player string) bool {
	clean := stripColors(listOutput)
	for _, part := range strings.FieldsFunc(clean, func(r rune) bool { return r == ',' || r == '\n' || r == ':' }) {
		if strings.EqualFold(strings.TrimSpace(part), player) {
			return true
		}
	}
	return false
}

func stripColors(s string) string {
	var b strings.Builder
	skip := false
	for _, r := range s {
		if skip {
			skip = false
			continue
		}
		if r == '§' {
			skip = true
			continue
		}
		b.WriteRune(r)
	}
	return b.String()
}

// minecraftServers reads the server list of Minecraft Bedrock on this PC (external_servers.txt).
func minecraftServers() []string {
	var files []string
	if la := os.Getenv("LOCALAPPDATA"); la != "" {
		files = append(files, filepath.Join(la, `Packages\Microsoft.MinecraftUWP_8wekyb3d8bbwe\LocalState\games\com.mojang\minecraftpe\external_servers.txt`))
	}
	if ad := os.Getenv("APPDATA"); ad != "" {
		m, _ := filepath.Glob(filepath.Join(ad, "Minecraft Bedrock", "Users", "*", "games", "com.mojang", "minecraftpe", "external_servers.txt"))
		files = append(files, m...)
	}
	seen := map[string]bool{}
	var hosts []string
	for _, f := range files {
		data, err := os.ReadFile(f)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(data), "\n") {
			// index:name:host:port:timestamp
			parts := strings.Split(strings.TrimSpace(line), ":")
			if len(parts) >= 4 {
				h := strings.TrimSpace(parts[2])
				if h != "" && !seen[strings.ToLower(h)] {
					seen[strings.ToLower(h)] = true
					hosts = append(hosts, h)
				}
			}
		}
	}
	return hosts
}

// autoFind logs in without a code on every candidate at the same time ("auto"):
// only the server you are online on (same IP, with OP) shows the popup in the game.
func autoFind(hosts []string, port int) (SavedServer, error) {
	type result struct {
		s   SavedServer
		err error
	}
	ch := make(chan result, len(hosts))
	for _, h := range hosts {
		go func(h string) {
			var c Rcon
			reply, err := c.Connect(h, port, "auto")
			c.Close()
			if err != nil {
				ch <- result{err: fmt.Errorf("%s: %v", h, err)}
				return
			}
			token, player := parseReply(reply)
			ch <- result{s: SavedServer{Host: h, Port: port, Login: "token:" + token, Player: player}}
		}(h)
	}
	var errs []string
	for range hosts {
		r := <-ch
		if r.err == nil {
			return r.s, nil
		}
		errs = append(errs, r.err.Error())
	}
	return SavedServer{}, fmt.Errorf("%s", strings.Join(errs, "\n"))
}

func addServer(entry SavedServer) int {
	i := findServer(entry.Host, entry.Port)
	if i >= 0 {
		cfg.Servers[i] = entry
	} else {
		cfg.Servers = append(cfg.Servers, entry)
		i = len(cfg.Servers) - 1
	}
	cfg.Current = i
	saveConfig()
	return i
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(v)
}

func decode(r *http.Request, v any) bool {
	return r.Method == http.MethodPost && json.NewDecoder(r.Body).Decode(v) == nil
}

func main() {
	loadConfig()
	ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", uiPort))
	if err != nil {
		openWindow(fmt.Sprintf("http://127.0.0.1:%d/", uiPort)) // already running
		return
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		w.Write(uiHTML)
	})
	mux.HandleFunc("/api/state", func(w http.ResponseWriter, r *http.Request) {
		cfgMu.Lock()
		defer cfgMu.Unlock()
		writeJSON(w, publicState())
	})
	mux.HandleFunc("/api/save", func(w http.ResponseWriter, r *http.Request) {
		var c struct {
			Buttons []UserButton `json:"buttons"`
			History []string     `json:"history"`
		}
		if decode(r, &c) {
			cfgMu.Lock()
			cfg.Buttons, cfg.History = c.Buttons, c.History
			saveConfig()
			cfgMu.Unlock()
		}
		writeJSON(w, map[string]any{"ok": true})
	})
	// New server: pair with a code (or a password).
	mux.HandleFunc("/api/connect", func(w http.ResponseWriter, r *http.Request) {
		var c struct {
			Host     string `json:"host"`
			Port     int    `json:"port"`
			Login    string `json:"login"`
			Remember bool   `json:"remember"`
		}
		if !decode(r, &c) {
			http.Error(w, "bad request", 400)
			return
		}
		cfgMu.Lock()
		defer cfgMu.Unlock()
		reply, err := rcon.Connect(c.Host, c.Port, c.Login)
		if err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		token, player := parseReply(reply)
		login := ""
		if token != "" {
			login = "token:" + token // pairing: keep the token, never the one-time code
		} else if c.Remember {
			login = c.Login
		}
		addServer(SavedServer{Host: c.Host, Port: c.Port, Login: login, Player: player})
		writeJSON(w, map[string]any{"ok": true, "player": player, "state": publicState()})
	})
	mux.HandleFunc("/api/use", func(w http.ResponseWriter, r *http.Request) {
		var c struct {
			Index int `json:"index"`
		}
		if !decode(r, &c) {
			http.Error(w, "bad request", 400)
			return
		}
		cfgMu.Lock()
		defer cfgMu.Unlock()
		if err := useServer(c.Index); err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error(), "state": publicState()})
			return
		}
		writeJSON(w, map[string]any{"ok": true, "state": publicState()})
	})
	mux.HandleFunc("/api/forget", func(w http.ResponseWriter, r *http.Request) {
		var c struct {
			Index int `json:"index"`
		}
		if decode(r, &c) {
			cfgMu.Lock()
			if c.Index >= 0 && c.Index < len(cfg.Servers) {
				if c.Index == cfg.Current {
					rcon.Close()
					cfg.Current = -1
				} else if c.Index < cfg.Current {
					cfg.Current--
				}
				cfg.Servers = append(cfg.Servers[:c.Index], cfg.Servers[c.Index+1:]...)
				saveConfig()
			}
			cfgMu.Unlock()
		}
		cfgMu.Lock()
		defer cfgMu.Unlock()
		writeJSON(w, map[string]any{"ok": true, "state": publicState()})
	})
	mux.HandleFunc("/api/autodetect", func(w http.ResponseWriter, r *http.Request) {
		cfgMu.Lock()
		defer cfgMu.Unlock()
		i, ok := autodetect()
		if !ok && cfg.Current >= 0 {
			useServer(cfg.Current) // fall back to the last one used
		}
		writeJSON(w, map[string]any{"found": ok, "index": i, "state": publicState()})
	})
	mux.HandleFunc("/api/mcservers", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, map[string]any{"hosts": minecraftServers()})
	})
	// Without a code: try the given host (or 127.0.0.1 + your Minecraft server list) with "auto".
	mux.HandleFunc("/api/auto", func(w http.ResponseWriter, r *http.Request) {
		var c struct {
			Host string `json:"host"`
			Port int    `json:"port"`
		}
		if !decode(r, &c) {
			http.Error(w, "bad request", 400)
			return
		}
		if c.Port == 0 {
			c.Port = 19140
		}
		hosts := []string{strings.TrimSpace(c.Host)}
		if hosts[0] == "" {
			hosts = append([]string{"127.0.0.1"}, minecraftServers()...)
		}
		s, err := autoFind(hosts, c.Port)
		cfgMu.Lock()
		defer cfgMu.Unlock()
		if err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error(), "state": publicState()})
			return
		}
		i := addServer(s)
		if err := useServer(i); err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error(), "state": publicState()})
			return
		}
		writeJSON(w, map[string]any{"ok": true, "host": s.Host, "player": s.Player, "state": publicState()})
	})
	mux.HandleFunc("/api/disconnect", func(w http.ResponseWriter, r *http.Request) {
		rcon.Close()
		writeJSON(w, map[string]any{"ok": true})
	})
	mux.HandleFunc("/api/cmd", func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Command string `json:"command"`
		}
		if !decode(r, &req) {
			http.Error(w, "bad request", 400)
			return
		}
		cfgMu.Lock()
		player := ""
		if cfg.Current >= 0 && cfg.Current < len(cfg.Servers) {
			player = cfg.Servers[cfg.Current].Player
		}
		cfgMu.Unlock()
		cmd := strings.ReplaceAll(req.Command, "{player}", player)
		out, err := rcon.Exec(cmd)
		if err != nil && !rcon.Connected() {
			// one automatic reconnect (server restart, Wi-Fi hiccup) using the saved token
			cfgMu.Lock()
			if useServer(cfg.Current) == nil {
				out, err = rcon.Exec(cmd)
			}
			cfgMu.Unlock()
		}
		if err != nil {
			writeJSON(w, map[string]any{"ok": false, "error": err.Error(), "output": out, "command": cmd, "connected": rcon.Connected()})
			return
		}
		writeJSON(w, map[string]any{"ok": true, "output": out, "command": cmd, "connected": true})
	})
	mux.HandleFunc("/api/ping", func(w http.ResponseWriter, r *http.Request) {
		pingMu.Lock()
		lastPing = time.Now()
		pingMu.Unlock()
		writeJSON(w, map[string]any{"connected": rcon.Connected()})
	})

	// The program ends a little after its window was closed.
	go func() {
		for {
			time.Sleep(5 * time.Second)
			pingMu.Lock()
			idle := time.Since(lastPing)
			pingMu.Unlock()
			if idle > 45*time.Second {
				rcon.Close()
				os.Exit(0)
			}
		}
	}()

	go openWindow(fmt.Sprintf("http://127.0.0.1:%d/", uiPort))
	http.Serve(ln, mux)
}

// openWindow shows the menu as its own app window (Edge/Chrome app mode), else in the browser.
func openWindow(url string) {
	if runtime.GOOS == "windows" {
		candidates := []string{
			filepath.Join(os.Getenv("ProgramFiles(x86)"), `Microsoft\Edge\Application\msedge.exe`),
			filepath.Join(os.Getenv("ProgramFiles"), `Microsoft\Edge\Application\msedge.exe`),
			filepath.Join(os.Getenv("ProgramFiles"), `Google\Chrome\Application\chrome.exe`),
			filepath.Join(os.Getenv("ProgramFiles(x86)"), `Google\Chrome\Application\chrome.exe`),
			filepath.Join(os.Getenv("LocalAppData"), `Google\Chrome\Application\chrome.exe`),
		}
		for _, p := range candidates {
			if _, err := os.Stat(p); err == nil {
				if exec.Command(p, "--app="+url, "--window-size=1040,720").Start() == nil {
					return
				}
			}
		}
		exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
		return
	}
	for _, b := range []string{"xdg-open", "open"} {
		if exec.Command(b, url).Start() == nil {
			return
		}
	}
	fmt.Println("Menü öffnen:", url)
}
