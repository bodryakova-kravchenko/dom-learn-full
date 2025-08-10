// app.js
// Global JS for DOMLearn: theme toggle and lesson test handlers.

(function(){
  // Apply saved theme immediately on script load (before DOMContentLoaded) to avoid FOUC
  try {
    var key = 'domlearn-theme';
    var saved = localStorage.getItem(key);
    if (saved) {
      document.addEventListener('DOMContentLoaded', function(){
        document.body.className = saved;
      });
    }
  } catch (e) {}

  document.addEventListener('DOMContentLoaded', function(){
    // Theme toggle
    var key = 'domlearn-theme';
    var btn = document.getElementById('themeToggle');
    if (btn) {
      btn.addEventListener('click', function(){
        var cur = document.body.classList.contains('theme-dark') ? 'theme-dark' : 'theme-light';
        var next = cur === 'theme-dark' ? 'theme-light' : 'theme-dark';
        document.body.classList.remove('theme-dark','theme-light');
        document.body.classList.add(next);
        try { localStorage.setItem(key, next); } catch (e) {}
      });
    }

    // Lesson tests: instant check
    document.querySelectorAll('.test-question').forEach(function(qEl){
      var correct = parseInt(qEl.dataset.correct||'-1',10);
      var answered = false;
      qEl.querySelectorAll('.answer').forEach(function(btn, i){
        btn.addEventListener('click', function(){
          if (answered) return;
          answered = true;
          var idx = parseInt(btn.dataset.idx,10);
          qEl.querySelectorAll('.answer').forEach(function(b, j){
            if (j === correct) { b.classList.add('correct'); b.textContent = '✔ ' + b.textContent; }
            if (j === idx && j !== correct) { b.classList.add('wrong'); b.textContent = '✘ ' + b.textContent; }
            b.disabled = true;
          });
        });
      });
    });
  });
})();
