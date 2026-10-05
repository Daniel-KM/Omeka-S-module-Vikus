Vikus (module for Omeka S)
==========================

> __New versions of this module and support for Omeka S version 4.1 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Vikus] is a module for [Omeka S] that integrates the [Vikus Viewer], a zoomable
canvas that lets visitors explore large image collections by time, map and
keywords.

It is served locally, without third party services (cdn), so it is fully
GDPR-compliant.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

The module uses external libraries, so use the release zip to install it, or use
and init the source.

- From the zip

Download the last release [Vikus.zip] from the list of releases, and uncompress
it in the `modules` directory.

- From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Vikus`, go to the root of the module, and run:

```sh
composer install --no-dev
```

Then install it like any other Omeka module and follow the config instructions.

- For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/Vikus/phpunit.xml --testdox
```


Usage
-----

Once installed, the module provides a resource page block to display a zoomable
canvas of items, filterable by year and keywords. A direct link is appended to
see the Vikus in full page.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

```sh
# database dump example
mysqldump -u omeka -p omeka | gzip > "omeka.$(date +%Y%m%d_%H%M%S).sql.gz"
```


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


[Vikus Viewer] is published under the [MIT] license.


Copyright
---------

* Module Vikus for Omeka S: copyright Daniel Berthereau, 2026.
* Christopher Pietsch for [Vikus Viewer]


[Vikus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus
[Omeka S]: https://omeka.org/s
[Vikus Viewer]: https://github.com/cpietsch/vikus-viewer
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Vikus.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus/-/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://opensource.org/licenses/MIT
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
