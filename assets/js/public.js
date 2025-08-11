(function(){
  function normalizeHref(href){
    try{
      var a = document.createElement('a');
      a.href = href;
      var path = a.pathname || '';
      if (!path && href && href.indexOf('/') === 0) path = href;
      path = path.replace(/\/+$/,'');
      return path.toLowerCase();
    }catch(e){
      return String(href||'').replace(/[#?].*$/,'').replace(/\/+$/,'').toLowerCase();
    }
  }

  function getHpMenuRoot(){
    // Prefer the user account submenu
    var root = document.querySelector('.hp-menu--user-account');
    if (root) return root;
    // Fallbacks
    root = document.querySelector('ul.hp-menu.hp-menu--user-account');
    if (root) return root;
    root = document.querySelector('.hp-menu, nav.hp-menu');
    return root || null;
  }

  function textLenBucket(txt){
    // Returns "1", "2", or "3" based on count length; 9+ counts as 2.
    var t = (String(txt || '').trim());
    if (!t) return "1";
    if (t.indexOf('+') !== -1) return "2";
    var digits = t.replace(/[^0-9]/g,'');
    if (digits.length >= 3) return "3";
    if (digits.length === 2) return "2";
    return "1";
  }

  function setLenAttr(el){
    try{
      var t = (el.textContent || '').trim();
      el.setAttribute('data-len', textLenBucket(t));
    }catch(e){}
  }

  function collectHpCounts(){
    var out = {};
    var root = getHpMenuRoot();
    if (!root) return out;

    var anchors = root.querySelectorAll('a[href]');
    for (var i=0;i<anchors.length;i++){
      var a = anchors[i];

      // Find any <small> or <span> inside the link that looks like a numeric badge (e.g. "1" or "9+").
      var badge = null;
      var cand  = a.querySelectorAll('small, span');
      for (var j=0;j<cand.length;j++){
        var t = (cand[j].textContent || '').trim();
        if (/^[0-9]+(\+)?$/.test(t)) { badge = cand[j]; break; }
      }
      if (!badge) continue;

      var txt = (badge.textContent || '').trim();
      var num = parseInt(txt.replace(/[^0-9]/g,''),10);
      if (!num || num < 1) continue;

      var href = a.getAttribute('href') || a.href || '';
      var key  = normalizeHref(href);
      if (!key) continue;

      out[key] = txt; // keep "9+" if present

      // Ensure HP badges get the len attribute too (for HP mobile menu visuals)
      setLenAttr(badge);
    }
    return out;
  }

  function addBadges(){
    var wcNav = document.querySelector('.woocommerce-MyAccount-navigation, nav.woocommerce-MyAccount-navigation');
    if(!wcNav) return;

    // Live DOM counts from HP (most accurate)
    var hpCounts = collectHpCounts();

    // Merge PHP fallback (if present and not already in DOM map)
    var phpMap = (window.AMEHP_DATA && window.AMEHP_DATA.badges) ? window.AMEHP_DATA.badges : {};
    if (phpMap){
      Object.keys(phpMap).forEach(function(k){
        var item = phpMap[k] || {};
        if (!item.url || !item.count) return;
        var key = normalizeHref(item.url);
        if (!key) return;
        if (!(key in hpCounts)) {
          var n = parseInt(String(item.count).replace(/[^0-9]/g,''),10);
          if (n && n > 0) hpCounts[key] = String(item.count);
        }
      });
    }

    if (Object.keys(hpCounts).length === 0) return;

    var anchors = wcNav.querySelectorAll('a[href]');
    for (var i=0;i<anchors.length;i++){
      var a = anchors[i];
      // Avoid duplicates
      if (a.querySelector('.hp-badge, .amehp-badge')) continue;

      var href = a.getAttribute('href') || a.href || '';
      var key  = normalizeHref(href);
      if (!key) continue;

      var val = hpCounts[key];

      // If not found, fuzzy match last path segment (helps when one menu has extra segments)
      if (!val) {
        var lastSeg = key.split('/').filter(Boolean).pop();
        if (lastSeg) {
          var found = null;
          for (var hpKey in hpCounts){
            if (!Object.prototype.hasOwnProperty.call(hpCounts, hpKey)) continue;
            if (hpKey.split('/').filter(Boolean).pop() === lastSeg) { found = hpKey; break; }
          }
          if (found) val = hpCounts[found];
        }
      }

      if (!val) continue;

      var b = document.createElement('small');
      b.className = 'hp-badge amehp-badge';
      b.textContent = String(val);
      setLenAttr(b);
      a.appendChild(b);
    }
  }

  function run(){
    addBadges();

    // Observe Woo nav (in case theme populates late)
    var wcNav = document.querySelector('.woocommerce-MyAccount-navigation, nav.woocommerce-MyAccount-navigation');
    if (wcNav){
      var mo1 = new MutationObserver(function(){ addBadges(); });
      mo1.observe(wcNav, {childList:true, subtree:true});
    }

    // Observe HP user menu, which might toggle open/closed or render later
    var hpRoot = getHpMenuRoot();
    if (hpRoot){
      var mo2 = new MutationObserver(function(){
        // Also ensure existing HP badges have the length attribute
        var hpBadges = hpRoot.querySelectorAll('small, span');
        for (var k=0;k<hpBadges.length;k++){
          var node = hpBadges[k];
          var t = (node.textContent || '').trim();
          if (/^[0-9]+(\+)?$/.test(t)) setLenAttr(node);
        }
        addBadges();
      });
      mo2.observe(hpRoot, {childList:true, subtree:true});
    }

    window.addEventListener('load', function(){ setTimeout(addBadges, 50); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
