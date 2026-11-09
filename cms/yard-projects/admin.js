(function ($) {
  'use strict';
  function syncGallery() {
    $('#yard-gallery-ids').val($('#yard-gallery > li').map(function () { return this.dataset.id; }).get().join(','));
  }
  function galleryItem(media) {
    var li = $('<li>').attr('data-id', media.id);
    $('<img>').attr({src: (media.sizes && media.sizes.thumbnail ? media.sizes.thumbnail.url : media.url), alt: ''}).appendTo(li);
    var buttons = $('<div>').appendTo(li);
    [['yard-up', '↑', 'Раньше'], ['yard-down', '↓', 'Позже'], ['yard-remove', 'Убрать', 'Убрать из галереи']].forEach(function (b) {
      $('<button type="button" class="button">').addClass(b[0]).text(b[1]).attr('aria-label', b[2]).appendTo(buttons);
    });
    return li;
  }
  $('#yard-gallery').sortable({update: syncGallery, tolerance: 'pointer'});
  $('#yard-add-gallery').on('click', function () {
    var frame = wp.media({title: 'Фотографии проекта', button: {text: 'Добавить в галерею'}, library: {type: 'image'}, multiple: true});
    frame.on('select', function () {
      frame.state().get('selection').each(function (item) {
        if (!$('#yard-gallery > li[data-id="' + Number(item.id) + '"]').length) { $('#yard-gallery').append(galleryItem(item.toJSON())); }
      });
      syncGallery();
    });
    frame.open();
  });
  $('#yard-gallery').on('click', '.yard-remove, .yard-up, .yard-down', function () {
    var li = $(this).closest('li');
    if ($(this).hasClass('yard-remove')) { li.remove(); }
    else if ($(this).hasClass('yard-up')) { li.prev().before(li); }
    else { li.next().after(li); }
    syncGallery();
  });

  function previewFormat(img) {
    if (!img || !img.naturalWidth || !img.naturalHeight) return 'square';
    var ratio=img.naturalWidth/img.naturalHeight;
    return ratio>1.2?'horizontal':(ratio<.85?'vertical':'square');
  }
  function applyCrop(control) {
    var field=control.data('field')==='home_image'?'home':'card';
    var x=Number(control.find('[name="yard_'+field+'_crop_x"]').val());
    var y=Number(control.find('[name="yard_'+field+'_crop_y"]').val());
    control.find('.yard-media-preview img').css('object-position',(Number.isFinite(x)?x:50)+'% '+(Number.isFinite(y)?y:50)+'%');
  }
  function syncCardPreview() {
    var cover=$('.yard-media-control[data-field="cover"]');
    var chosen=$('[name="yard_card_format"]').val()||'auto';
    cover.attr('data-format',chosen==='auto'?previewFormat(cover.find('.yard-media-preview img')[0]):chosen);
    applyCrop(cover);
  }
  $('.yard-media-control').on('input change','.yard-crop-controls input[type="range"]',function(){applyCrop($(this).closest('.yard-media-control'));});
  $('.yard-media-control').on('click','.yard-center-crop',function(){
    var control=$(this).closest('.yard-media-control');
    control.find('.yard-crop-controls input[type="range"]').val(50);
    applyCrop(control);
  });
  $('[name="yard_card_format"]').on('change',syncCardPreview);
  $('.yard-media-control[data-field="cover"]').on('load','.yard-media-preview img',syncCardPreview);
  $('.yard-media-control').each(function(){applyCrop($(this))});
  syncCardPreview();
  $('.yard-pick-image').on('click',function(){
    var control=$(this).closest('.yard-media-control');
    var frame=wp.media({title:control.find('h3').text(),button:{text:'Выбрать'},library:{type:'image'},multiple:false});
    frame.on('select',function(){
      var item=frame.state().get('selection').first().toJSON();
      var isHome=control.data('field')==='home_image';
      if(Number(control.find('input[type="hidden"]').val())!==Number(item.id)){
        control.find('.yard-crop-controls input[type="range"]').val(50);
      }
      control.find('input[type="hidden"]').val(item.id);
      var sizes=item.sizes||{}, size=isHome?(sizes.yard_large||sizes.large||sizes.full):(sizes.yard_card||sizes.large||sizes.full);
      control.find('.yard-media-preview').empty().append($('<img>').attr({src:size?size.url:item.url,alt:'Предпросмотр кадрирования'}));
      control.find('.yard-crop-controls').prop('hidden',false);
      applyCrop(control);
      if(isHome)control.attr('data-format','horizontal');else syncCardPreview();
    });
    frame.open();
  });
  $('.yard-clear-image').on('click',function(){
    var control=$(this).closest('.yard-media-control');
    control.find('input[type="hidden"]').val('0');
    control.find('.yard-media-preview').empty();
    control.find('.yard-crop-controls').prop('hidden',true);
    if(control.data('field')==='cover')syncCardPreview();
  });
})(jQuery);
