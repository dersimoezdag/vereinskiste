(function () {
    'use strict';
    var element = document.getElementById('interface-texts');
    var texts = element ? JSON.parse(element.textContent) : {};
    window.vkText = function (key) { return Object.prototype.hasOwnProperty.call(texts, key) ? texts[key] : key; };
}());
