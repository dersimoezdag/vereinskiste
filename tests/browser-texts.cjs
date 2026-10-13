const fs = require('node:fs');
module.exports = function (window) {
    if (window.vkText) return;
    const texts = {};
    const source = fs.readFileSync(require.resolve('../app/src/Text/de/interface.php'), 'utf8');
    for (const match of source.matchAll(/'([^']+)'\s*=>\s*'((?:\\.|[^'\\])*)'/g)) {
        if (match[1].startsWith('js.')) texts[match[1]] = match[2].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    }
    const element = window.document.createElement('script');
    element.id = 'interface-texts'; element.type = 'application/json'; element.textContent = JSON.stringify(texts);
    window.document.body.appendChild(element);
    window.eval(fs.readFileSync(require.resolve('../public/assets/interface-texts.js'), 'utf8'));
};
