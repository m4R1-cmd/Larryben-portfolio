// Ask before deleting (replaces the old inline onsubmit so the strict security policy can stay on)
document.addEventListener('submit', function (e) {
  var msg = e.target && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
