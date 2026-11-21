package main

import (
	"bufio"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"strings"
	"sync"
	"time"
)

// Source RCON client (the protocol BetterPMMP speaks on rcon.port).
type Rcon struct {
	mu     sync.Mutex
	conn   net.Conn
	r      *bufio.Reader
	nextID int32
	addr   string
}

const (
	typeAuth     = 3
	typeExec     = 2
	typeResponse = 0
)

// Connect logs in. password is the RCON password, "pair:<code>" or "token:<token>".
// It returns the text the server sent with the login (BetterPMMP: "token:..." / "player:...").
func (c *Rcon) Connect(host string, port int, password string) (string, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.closeLocked()
	addr := net.JoinHostPort(strings.TrimSpace(host), fmt.Sprint(port))
	conn, err := net.DialTimeout("tcp", addr, 6*time.Second)
	if err != nil {
		return "", fmt.Errorf("keine Verbindung zu %s (Server an? RCON an? Port %d TCP offen?)", addr, port)
	}
	c.conn = conn
	c.r = bufio.NewReader(conn)
	c.addr = addr
	id := c.id()
	if err := c.write(id, typeAuth, password); err != nil {
		c.closeLocked()
		return "", err
	}
	reply := ""
	wait := 10 * time.Second
	if password == "auto" {
		wait = 75 * time.Second // the player confirms in the game
	}
	for {
		rid, typ, body, err := c.read(wait)
		if err != nil {
			c.closeLocked()
			return "", errors.New("Server hat die Anmeldung abgelehnt (Code falsch/abgelaufen oder IP kurz gesperrt)")
		}
		if typ == 2 { // auth response
			if rid == -1 {
				c.closeLocked()
				if msg, ok := strings.CutPrefix(reply, "error:"); ok {
					return "", errors.New(msg)
				}
				if strings.HasPrefix(password, "pair:") {
					return "", errors.New("Code falsch oder abgelaufen - im Spiel neu /console koppeln")
				}
				return "", errors.New("Anmeldung abgelehnt (Passwort/Kopplung ungültig)")
			}
			return reply, nil
		}
		if typ == typeResponse && rid == id {
			reply += body
		}
	}
}

func (c *Rcon) Connected() bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.conn != nil
}

func (c *Rcon) Close() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.closeLocked()
}

func (c *Rcon) closeLocked() {
	if c.conn != nil {
		c.conn.Close()
	}
	c.conn = nil
	c.r = nil
}

func (c *Rcon) id() int32 {
	c.nextID++
	if c.nextID > 1<<30 {
		c.nextID = 1
	}
	return c.nextID
}

// Exec runs a console command and returns all output (long output comes in several packets;
// an empty marker packet after the command tells us when everything has arrived).
func (c *Rcon) Exec(cmd string) (string, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn == nil {
		return "", errors.New("nicht verbunden")
	}
	id := c.id()
	marker := c.id()
	if err := c.write(id, typeExec, cmd); err != nil {
		c.closeLocked()
		return "", err
	}
	if err := c.write(marker, typeResponse, ""); err != nil {
		c.closeLocked()
		return "", err
	}
	var out strings.Builder
	for {
		rid, _, body, err := c.read(30 * time.Second)
		if err != nil {
			c.closeLocked()
			return out.String(), errors.New("Verbindung zum Server verloren")
		}
		if rid == marker {
			return out.String(), nil
		}
		if rid == id {
			out.WriteString(body)
		}
	}
}

func (c *Rcon) write(id int32, typ int32, body string) error {
	payload := make([]byte, 0, 10+len(body))
	payload = binary.LittleEndian.AppendUint32(payload, uint32(id))
	payload = binary.LittleEndian.AppendUint32(payload, uint32(typ))
	payload = append(payload, body...)
	payload = append(payload, 0, 0)
	pkt := binary.LittleEndian.AppendUint32(nil, uint32(len(payload)))
	pkt = append(pkt, payload...)
	c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
	_, err := c.conn.Write(pkt)
	return err
}

func (c *Rcon) read(timeout time.Duration) (int32, int32, string, error) {
	c.conn.SetReadDeadline(time.Now().Add(timeout))
	var hdr [4]byte
	if _, err := io.ReadFull(c.r, hdr[:]); err != nil {
		return 0, 0, "", err
	}
	size := int32(binary.LittleEndian.Uint32(hdr[:]))
	if size < 10 || size > 1<<20 {
		return 0, 0, "", errors.New("ungültiges Paket")
	}
	buf := make([]byte, size)
	if _, err := io.ReadFull(c.r, buf); err != nil {
		return 0, 0, "", err
	}
	id := int32(binary.LittleEndian.Uint32(buf[0:4]))
	typ := int32(binary.LittleEndian.Uint32(buf[4:8]))
	return id, typ, string(buf[8 : size-2]), nil
}
