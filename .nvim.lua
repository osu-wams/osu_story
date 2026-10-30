vim.lsp.config("intelephense", {
  settings = {
    intelephense = {
      environment = {
        documentRoot = "/home/brabhamm/PhpstormProjects/cwsd8/docroot",
        includePaths = {
          "/home/brabhamm/PhpstormProjects/cwsd8/docroot/core",
          "/home/brabhamm/PhpstormProjects/cwsd8/docroot/modules",
          "/home/brabhamm/PhpstormProjects/cwsd8/docroot/profile",
          "/home/brabhamm/PhpstormProjects/cwsd8/docroot/themes",
          "/home/brabhamm/PhpstormProjects/cwsd8/vendor"
        },
      },
      files = {
        associations = { "*.php", "*.engine", "*.inc", "*.install", "*.module", "*.profile", "*.theme" },
      },
    },
  },
})
vim.filetype.add({
  extension = {
    inc = "php",
    install = "php",
    module = "php",
    profile = "php",
    theme = "php",
  }
})
