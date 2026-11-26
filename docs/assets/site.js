// BetterPMMP website: language switch and copy buttons
(function(){
  var root = document.documentElement;
  var saved = null;
  try{ saved = localStorage.getItem("lang"); }catch(e){}
  var lang = saved || ((navigator.language || "en").toLowerCase().indexOf("de") === 0 ? "de" : "en");

  function setLang(l){
    root.lang = l;
    document.querySelectorAll("[data-set-lang]").forEach(function(b){
      b.setAttribute("aria-pressed", b.dataset.setLang === l ? "true" : "false");
    });
    var t = document.querySelector("meta[name='title-" + l + "']");
    if(t){ document.title = t.content; }
    try{ localStorage.setItem("lang", l); }catch(e){}
  }

  document.querySelectorAll("[data-set-lang]").forEach(function(b){
    b.addEventListener("click", function(){ setLang(b.dataset.setLang); });
  });
  setLang(lang);

  document.querySelectorAll("pre").forEach(function(pre){
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "copy";
    btn.textContent = root.lang === "de" ? "Kopieren" : "Copy";
    btn.addEventListener("click", function(){
      var code = pre.querySelector("code");
      var text = (code || pre).innerText;
      var done = function(){
        btn.textContent = root.lang === "de" ? "Kopiert" : "Copied";
        setTimeout(function(){ btn.textContent = root.lang === "de" ? "Kopieren" : "Copy"; }, 1500);
      };
      if(navigator.clipboard){ navigator.clipboard.writeText(text).then(done, function(){}); }
    });
    pre.appendChild(btn);
  });
})();

// plugin hub: search and categories
(function(){
  var input = document.getElementById("hub-search");
  if(!input){ return; }
  var cards = Array.prototype.slice.call(document.querySelectorAll("#hub-grid .plugin"));
  var empty = document.getElementById("hub-empty");
  var cat = "";
  function apply(){
    var q = input.value.trim().toLowerCase();
    var shown = 0;
    cards.forEach(function(c){
      var ok = (!q || c.dataset.search.indexOf(q) !== -1) && (!cat || (" " + c.dataset.cats + " ").indexOf(" " + cat + " ") !== -1);
      c.hidden = !ok;
      if(ok){ shown++; }
    });
    empty.hidden = shown !== 0;
  }
  function placeholder(){ input.placeholder = document.documentElement.lang === "de" ? input.dataset.phDe : input.dataset.phEn; }
  input.addEventListener("input", apply);
  document.querySelectorAll(".chip").forEach(function(chip){
    chip.addEventListener("click", function(){
      cat = chip.dataset.cat;
      document.querySelectorAll(".chip").forEach(function(o){ o.setAttribute("aria-pressed", o === chip ? "true" : "false"); });
      apply();
    });
  });
  document.querySelectorAll("[data-set-lang]").forEach(function(b){ b.addEventListener("click", placeholder); });
  placeholder();
})();
