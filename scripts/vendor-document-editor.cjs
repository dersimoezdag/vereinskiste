// Runtime files and readable source from pinned, free npm packages; no CDN.
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '..');
const modules = path.join(root, 'tests/node_modules');
const target = path.join(root, 'public/assets/vendor/tinymce');
fs.mkdirSync(target, {recursive:true});
for (const name of ['tinymce.js','tinymce.min.js','license.md','notices.txt','plugins','themes','icons','models','skins']) {
    fs.cpSync(path.join(modules,'tinymce',name), path.join(target,name), {recursive:true});
}
fs.mkdirSync(path.join(target,'langs'), {recursive:true});
fs.copyFileSync(path.join(modules,'tinymce-i18n/langs8/de.js'),path.join(target,'langs/de.js'));
fs.copyFileSync(path.join(modules,'tinymce-i18n/LICENSE.md'),path.join(target,'langs/LICENSE'));
console.log('Free TinyMCE assets packaged.');
