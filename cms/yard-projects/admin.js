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
  function applyCrop(control) {
    var x = control.find('[name="yard_home_crop_x"]').val();
    var y = control.find('[name="yard_home_crop_y"]').val();
    control.find('.yard-media-preview img').css('object-position', x + '% ' + y + '%');
  }
  var home = $('.yard-media-control[data-field="home_image"]');
  home.on('input change', 'input[type="range"]', function () { applyCrop(home); });
  home.on('click', '.yard-center-crop', function () {
    home.find('input[type="range"]').val(50);
    applyCrop(home);
  });
  applyCrop(home);
  $('.yard-pick-image').on('click', function () {
    var control = $(this).closest('.yard-media-control');
    var frame = wp.media({title: control.find('h3').text(), button: {text: 'Выбрать'}, library: {type: 'image'}, multiple: false});
    frame.on('select', function () {
      var item = frame.state().get('selection').first().toJSON();
      var isHome = control.data('field') === 'home_image';
      var selection = control.find('input[type="hidden"]');
      if (isHome && Number(selection.val()) !== item.id) { control.find('input[type="range"]').val(50); }
      selection.val(item.id);
      var size = item.sizes && item.sizes[isHome ? 'yard_large' : 'thumbnail'];
      control.find('.yard-media-preview').empty().append($('<img>').attr({src: size ? size.url : item.url, alt: ''}));
      if (isHome) {
        control.find('.yard-crop-controls').prop('hidden', false);
        applyCrop(control);
      }
    });
    frame.open();
  });
  $('.yard-clear-image').on('click', function () {
    var control = $(this).closest('.yard-media-control'); control.find('input[type="hidden"]').val('0'); control.find('.yard-media-preview').empty();
    control.find('.yard-crop-controls').prop('hidden', true);
  });
})(jQuery);
