(function () {
 'use strict';
 var select=document.getElementById('user-member-id');if(!select)return;
 var name=document.querySelector('[data-linked-name]'),email=document.querySelector('[data-linked-email]');
 function sync(){var linked=select.value!=='';name.disabled=email.disabled=linked;if(linked){var option=select.selectedOptions[0];name.value=option.dataset.memberName||'';email.value=option.dataset.memberEmail||'';}}
 select.addEventListener('change',sync);sync();
 var unlock=document.querySelector('[data-member-link-unlock]');
 if(unlock){
  function enable(){select.disabled=false;document.querySelector('[data-member-link-unlocked]').value='1';unlock.hidden=true;select.focus();}
  unlock.addEventListener('dblclick',enable);
  var lastTap=0;
  unlock.addEventListener('pointerup',function(event){if(event.pointerType!=='touch')return;var now=Date.now();if(lastTap && now-lastTap<500){enable();lastTap=0;}else{lastTap=now;}});
  unlock.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();enable();}});
 }
})();
