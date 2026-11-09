// Inserted into the original page by build_release.py. CSS and layout stay in index.html.
var yardLegal = null;
function yardEscape(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
}
function yardApi(path) { return '/cms/?rest_route=' + encodeURIComponent('/yard/v1/' + path); }
function yardMediaUrl(value) {
  var url = new URL(String(value), location.origin);
  if (url.origin !== location.origin || !url.pathname.startsWith('/cms/wp-content/uploads/') || url.search || url.hash) { throw new Error('Invalid media URL'); }
  return url.href.replace(/&/g, '%26').replace(/"/g, '%22').replace(/'/g, '%27');
}
async function yardRequest(path, options) {
  var controller = new AbortController();
  var timeout = setTimeout(function () { controller.abort(); }, 15000);
  try {
    var response = await fetch(yardApi(path), Object.assign({cache:'no-store', credentials:'omit', signal:controller.signal}, options || {}));
    var body = await response.json();
    if (!response.ok) { throw new Error(body.message || 'Сервер временно недоступен.'); }
    return body;
  } finally { clearTimeout(timeout); }
}
var yardPayload;
try {
  yardPayload = window.YARD_INITIAL_DATA || await yardRequest('projects');
  if (!yardPayload || yardPayload.apiVersion !== 1 || !Array.isArray(yardPayload.projects)) { throw new Error('Unexpected projects response'); }
  yardLegal = yardPayload.legal;
} catch (error) {
  document.getElementById('app').innerHTML = '<section class="privacy"><h1>Проекты временно недоступны</h1><p>Обновите страницу через некоторое время. Связаться с бюро: <a href="mailto:yard.bureau@mail.ru">yard.bureau@mail.ru</a>.</p></section>';
  var yardErrorCookie = document.getElementById('cookieBanner');
  yardErrorCookie.hidden = false;
  yardErrorCookie.querySelector('button').onclick = function () { yardErrorCookie.hidden = true; };
  return;
}
var projects = yardPayload.projects.map(function (p, i) {
  if (!Number.isSafeInteger(p.id) || p.id < 0 || !Array.isArray(p.gallery) || !p.gallery.length) { throw new Error('Invalid project'); }
  return {
    id:p.id, name:yardEscape(p.name), year:yardEscape(p.year), stage:yardEscape(p.stage), location:yardEscape(p.location),
    intro:yardEscape(p.intro).replace(/\r?\n/g,'<br>'),
    description:yardEscape(p.description).replace(/\r?\n/g,'<br>'), image:yardMediaUrl(p.image), gallery:p.gallery.map(yardMediaUrl),
    cardFormat:['horizontal','vertical','square'].includes(p.cardFormat) ? p.cardFormat : 'square',
    cardSize:['small','medium','large'].includes(p.cardSize) ? p.cardSize : 'auto',
    homeImage:p.homeImage ? yardMediaUrl(p.homeImage) : null, showOnHome:p.showOnHome === true,
    hasHorizontalHero:p.hasHorizontalHero === true,
    cardCropX:yardCrop(p.cardCropX), cardCropY:yardCrop(p.cardCropY),
    homeCropX:yardCrop(p.homeCropX), homeCropY:yardCrop(p.homeCropY), homeOrder:Number(p.homeOrder) || 0,
    order:Number(p.order) || 0, displayIndex:i
  };
});
function yardProjectMeta(p) { return [p.stage,p.year].filter(Boolean).join(' · '); }

function yardCrop(value) { return Number.isInteger(value) ? Math.max(0, Math.min(100, value)) : 50; }
function yardHeroImage(p) {
  return img(heroImage(p), p.name).replace('<img ', '<img style="object-position:' + p.homeCropX + '% ' + p.homeCropY + '%" ');
}
