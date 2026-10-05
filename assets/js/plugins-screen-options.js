(function ($, ajaxurl) {

  $('.external-repositories-visibility-toggle').change(function () {
    const hidden = $(this).is(':checked')

    $.post(
      ajaxurl,
      {
        action: 'hidden-external-repositories',
        hidden: +hidden,
        screenoptionnonce: $('#screenoptionnonce').val()
      },
      function () {
        const row = $('tr[data-slug="external-repositories"]').toggle(!hidden)

        if (!row.length) {
          history.go(0)
        }
      })
  })

})(jQuery, ajaxurl)