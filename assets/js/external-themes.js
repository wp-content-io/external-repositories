(function ($, settings) {

  const repositories = settings.repositories || []
  const getExternalRepository = (slug) => repositories.find(repository => repository.slug === slug)

  for (const repository of repositories) {
    // Build nodes instead of HTML strings: repository names may come from the `external_repositories` filter
    const link = $('<a href="#"></a>').attr('data-sort', repository.slug).text(repository.name)
    $('.filter-links').append($('<li></li>').append(link))
  }

  $(function () {

    if (wp && wp.themes && wp.themes.view && wp.themes.RunInstaller?.view) {

      const collection = wp.themes.RunInstaller.view.collection
      collection.on('query:success', () => {
        const themeBrowserElement = $('.theme-browser')
        themeBrowserElement.find('p.external-repository-actions').remove()

        const repository = getExternalRepository(wp.themes.router.selectedTab)
        if (repository) {
          const settings_url = settings.i18n.settings_url.replace(':ID:', repository.ID)

          const button = $('<a class="button"></a>').attr('href', settings_url).text(settings.i18n.settings_button)
          themeBrowserElement.find('div.themes').before($('<p class="external-repository-actions"></p>').append(button))
        }
      })

      collection.on('query:fail', () => {
        const themeBrowserElement = $('.theme-browser')
        const repository = getExternalRepository(wp.themes.router.selectedTab)
        if (repository) {

          const notice = $('<div class="notice notice-error error"></div>').append($('<p></p>').text(settings.i18n.error_message))
          if (repository.ID) {
            const settings_url = settings.i18n.settings_url.replace(':ID:', repository.ID)
            const button = $('<a class="button external-repository-settings"></a>').attr('href', settings_url).text(settings.i18n.error_button)
            notice.append($('<p></p>').append(button))
          }

          collection.reset([])
          collection.trigger('themes:update')
          collection.trigger('query:success', 0)

          themeBrowserElement.find('p.external-repository-actions').remove()
          themeBrowserElement.find('div.notice-error').remove()
          themeBrowserElement.find('div.themes').before(notice)
        }
      })
    }
  })

})(jQuery, window.external_themes_settings || {})