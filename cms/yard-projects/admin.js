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
  $('.yard-pick-image').on('click', function () {
    var control = $(this).closest('.yard-media-control');
    var frame = wp.media({title: control.find('h3').text(), button: {text: 'Выбрать'}, library: {type: 'image'}, multiple: false});
    frame.on('select', function () {
      var item = frame.state().get('selection').first().toJSON();
      if (control.data('field') === 'home_image' && !(item.width > item.height * 1.2)) {
        window.alert('Для главной выберите горизонтальную фотографию: ширина должна быть минимум на 20% больше высоты.');
        return;
      }
      control.find('input').val(item.id);
      control.find('.yard-media-preview').empty().append($('<img>').attr({src: item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail.url : item.url, alt: ''}));
    });
    frame.open();
  });
  $('.yard-clear-image').on('click', function () {
    var control = $(this).closest('.yard-media-control'); control.find('input').val('0'); control.find('.yard-media-preview').empty();
  });
})(jQuery);
