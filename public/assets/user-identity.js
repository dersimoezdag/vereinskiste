(function () {
 'use strict';
 var select=document.getElementById('user-member-id');if(!select)return;
 var name=document.querySelector('[data-linked-name]'),email=document.querySelector('[data-linked-email]');
 select.addEventListener('change',function(){var linked=select.value!=='';name.readOnly=email.readOnly=linked;if(linked){var option=select.selectedOptions[0];name.value=option.dataset.memberName||'';email.value=option.dataset.memberEmail||'';}});
})();
