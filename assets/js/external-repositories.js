(function ($) {

  $(document).on('click', '.external-repositories-actions [data-action="delete"]', function (e) {

    if (!confirm($(this).text() + ' ?')) {
      e.preventDefault()
      return false
    }
  })

  $(document).on('click', '.replace-api-key', function (e) {

    e.preventDefault()
    const parent = $(this).parent()
    const template = document.getElementById('api-key-input')
    parent.html(template.content.cloneNode(true))
    parent.find('input').focus()

    return false
  })

  $(document).on('change', '.external-repositories-url-options [name="source"]', function () {
    const url = $(this).val()
    const input = $('.external-repositories-url')
    const currentUrl = input.val()

    if (url.startsWith('http')) {
      input.data('previous', currentUrl)
      input.val(url)
    } else {
      const previous = input.data('previous')
      if (previous) {
        input.val(previous)
      }
    }
  })

})(jQuery)