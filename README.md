Vikus Viewer (module for Omeka S)
=================================

> __New versions of this module and support for Omeka S version 4.1 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Vikus] is a module for [Omeka S] that integrates the [Vikus Viewer], a zoomable
canvas that lets visitors explore large image collections by time, map and
keywords.

An example can be found on the library of the [Musée de Bretagne].

The items of a site are displayed as thumbnails, spread on a timeline and
filterable by keywords. A click on an image opens a panel with its metadata, a
link to its record, and a large image.

The data of the visualization (a csv file and packed atlases of thumbnails) are
prepared by a background job and served as static files, so no query is done
when a visitor displays the page.

The size of the collection matters for the browser, though: the thumbnails are
packed in jpeg atlases of 2048×2048 pixels that are all downloaded and kept in
the memory of the graphic card. A few thousand items by visualization is a
reasonable target; beyond that, split the collection in multiple pages with a
narrower query. See the section about the size of a visualization below.

It is served locally, without third party services (cdn), so it is fully
GDPR-compliant.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

With big bases or on shared hosts, it is recommended to install the optional
module [Job Queue] to avoid to overload the server when preparing viewer data.

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

The php extension `imagick` (recommended) or `gd` is required to build the
atlases of thumbnails, and the directory `files/vikus/` must be writeable by
the web server. Both are checked during the installation.


Usage
-----

Add the block "Vikus Viewer" to a page of a site. The items of the site that
have an image are displayed; the other ones are skipped.

The viewer can also be displayed on the page of an item set: in the admin, go to
the site, then "Theme", then "Resource page blocks", and add the block "Vikus Viewer"
to a region of the page of the item sets. The items of the item set that are
visible in the site are then displayed, with the title of the item set as title
of the viewer.

### Options of the block

The form of the block is split into six collapsible sections (Items, Alignment,
Layouts, Map, Images, Style), using the core mechanism of the page editor.

| Option                                       | Default                                                                   | Comment                                                                                                                                                                                                                           |
|----------------------------------------------|---------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Items to display                             | all items of the site                                                     | Standard query of Omeka; only items with an image are kept; the sort of the query is overridden (by id, ascending) to page through the items reliably; a `limit` in the query caps the generation, like the main setting          |
| Title                                        | title of the page                                                         | Displayed in the header of the viewer                                                                                                                                                                                             |
| Presentation (markdown)                      | empty                                                                     | Content of the info panel, rendered as markdown                                                                                                                                                                                   |
| Events of the timeline                       | empty                                                                     | One event by line: `1789 = Title = Text = Extra`; only in the block                                                                                                                                                               |
| Property for the year                        | `dcterms:date`                                                            | The first number of four digits of the first value is used                                                                                                                                                                        |
| Property of the annotation carrying the year | empty                                                                     | When set, the year is read in this property of the value annotations of the values of the property for the year; when empty, the value itself is used                                                                             |
| Precision of the year                        | `year`                                                                    | `year`, `decade` or `century`: the year is rounded down to the first year of its decade or of its century, so the columns of the time layout are wider and the images bigger at the first display                                 |
| Properties for the keywords                  | `dcterms:subject`                                                         | Used for the cloud of keywords and the filter                                                                                                                                                                                     |
| Mode of the filter                           | `tags` (keyword cloud)                                                    | `tags` (keyword cloud), `hierarchical` (levels split on `:`) or `crossfilter` (a facet by property of the keywords)                                                                                                               |
| Properties of the detail panel               | `dcterms:title`, `dcterms:creator`, `dcterms:date`, `dcterms:description` | One row by property, with its translated label                                                                                                                                                                                    |
| Properties for the group layouts             | empty                                                                     | A layout by property in the navigation, grouping the items by their first value                                                                                                                                                   |
| Titles of the layouts                        | empty                                                                     | One title by line: `dcterms:subject = Themes`; the label of the property is used when not set; the keys `time` and `map` rename the two layouts of the module; a short title is advised, the buttons are narrow                   |
| Add a map layout (module Mapping)            | no                                                                        | A layout placing the items on an equirectangular map, from the points of the module Mapping                                                                                                                                       |
| Isolated points of the map                   | move them to the border                                                   | The map fills the canvas, so a few distant points reduce the area where most of the items are to a few pixels: move them to the border of the dense area, hide them on the map only, or keep them where they are (from 50 points) |
| Tiles of the base map                        | `https://tile.openstreetmap.org/{z}/{x}/{y}.png`                          | Url template of a tile server, with the placeholders `{z}`, `{x}` and `{y}`; the tiles of OpenStreetMap are not meant for a public site                                                                                           |
| Attribution of the base map                  | `© OpenStreetMap contributors`                                            | Credit displayed in the corner of the map, as the licence of the tiles requires it; a plain text, the tags are removed                                                                                                            |
| Opacity of the base map                      | 0.6                                                                       | From 0 (invisible) to 1 (full): a faded map keeps the images readable                                                                                                                                                             |
| Hide the items without coordinates           | no                                                                        | The items absent from the file of the map layout are kept in the canvas by the viewer, dimmed on a ring around the map: hide them on this layout, along with the items excluded by the current filter                             |
| Source of the detailed images                | automatic                                                                 | `automatic` (iiif when available), `omeka` (large and original) or `iiif`                                                                                                                                                         |
| Add a link to the item in the detail panel   | yes                                                                       | Row "View the record", pointing to the item page of the site                                                                                                                                                                      |
| Number of columns by year                    | 6                                                                         | Number of images side by side inside a year, from 1 to 50; the columns of a group layout are derived from it, so that its width matches the one of the time layout                                                                |
| Height of the viewer (css)                   | `80vh`                                                                    | Any css length (`px`, `vh`, `em`, `rem`, `%`)                                                                                                                                                                                     |
| Colors (12 options)                          | colors of the reference config of the viewer                              | `#rrggbb` only; the text shadow is derived from the background of the canvas                                                                                                                                                      |

At the first display of a viewer in a browser session, a discreet hint is shown
at the bottom of the canvas ("Scroll or pinch to zoom, click an image to open
its record."): the viewer itself gives no sign that the canvas can be zoomed. It
disappears at the first interaction with the canvas or after eight seconds, and
it is not shown again in the session.

The label of the link itself ("Link") is hardcoded in the upstream viewer and
cannot be translated by the module; only the name of the row ("View the record")
is.

The year requires a number of four digits, so the years before 1000 must be
written with four digits (`0476`, `-0050`), as EDTF does: a date with unknown
digits (`18XX~`) gives no year, and the item is simply absent from the layout
"Time", but placed in the other layouts.

The option "Maximum number of items" of a block or of the site settings keeps
only the first items of the query; empty, all of them are generated. A global
setting ("Hard limit of items by visualization", 10000 by default) is a safety
limit of the background job that this option cannot exceed. The form of the
block displays the number of items of the query, the number displayed when it
is smaller, and warns when it is too big.

The option "Size of the sprites" sets the size of the thumbnails packed in the
atlases: 256 px by default, or 128 px, that divides the number of atlases, so
the memory of the graphic card, by four, at the cost of blurry images when the
visitor zooms in before the detailed image is loaded. Changing it rebuilds the
atlases.

#### Events of the timeline

Contextual events may be displayed under the timeline, one by line, like
`1789 = Night of the 4th August = Abolition of feudalism = Extra note`. Only the
year is required and it is extracted like the year of an item, so a full date
may be written. The field is displayed as a list of rows, a year and the rest of
the line, that may be reordered; the button "Text" switches back to the raw
lines. The years of the events widen the axis of the viewer, so an event may be
set outside of the range of the collection. This option belongs to the block
only: the page of an item set has no place to define it.

#### Mode of the filter

Three modes are available. With `tags` (the default), the keywords are displayed
as a cloud of tags. With `hierarchical`, the viewer splits each keyword on `:`
and displays the levels one by one, so keywords must be written like `Animals:Mammals:Dog`.
With `crossfilter`, the keyword bar is replaced by a panel of facets, one by
property of the keywords, whose counts are updated when a value is selected. A
facet is a single value, because the viewer handles a list of values for the
column of the keywords only, so the first value of each property is used.

#### Group layouts

Each selected property adds a button to the navigation of the viewer, which
groups the items by the first value of that property instead of the year. The
number of columns is the one of the block. An item without value is grouped
under the label "Unknown", because the viewer places an item with an empty key
outside of the canvas; a value made of spaces only counts as empty. The groups
are displayed in the order of their first appearance in the collection: the
viewer sorts the keys of a layout numerically, which does nothing for textual
values. Two properties with the same label get distinct buttons, the second one
suffixed by its term.

The option "Titles of the layouts" is displayed as a list of rows, a property
and a title. The picker "Add…" lists the properties selected above, so a title
is set without typing a term. Two keys are reserved and typed by hand: `time`
renames the first layout and `map` the map layout, whose default titles are the
translations of "Time" and "Map".

The timeline is displayed for these layouts too, but its events are placed by
year, so they only appear on the layout "Time". An item without year does not
appear on the layout "Time" either, for the same reason as an empty group key,
but it remains in the group layouts, the filter and the search.

#### Map layout

When the module [Mapping] is active, the option "Add a map layout" adds a button
to the navigation of the viewer, which places each item at its geographic
coordinates instead of its year. The first point of an item is used, the
features being sorted by id, so the same point is kept from one generation to
the next; a line or a polygon is ignored. The layout uses the same csv mechanism
as the similarity layouts of the viewer (t-SNE and the like), with an
equirectangular projection: the longitude is the abscissa and the latitude the
ordinate, and the viewer rescales both on the extent of the collection, so the
map fills the canvas whatever the area covered. A collection straddling the
antimeridian (±180°) is therefore stretched over the whole width, the eastern
and the western items being placed at the two ends. There is no background map:
the viewer draws the images only.

Because the viewer rescales the coordinates on the extent of the collection, a
few distant points reduce the area where most of the items are to a few pixels.
The option "Isolated points of the map" says what to do with them. By default
("Move them to the border of the dense area"), the coordinates are clamped to
the range of the second and of the ninety-eighth percentiles of each axis: the
isolated points are pinned to the border of the dense area instead of stretching
it. With "Hide them on the map", they are dropped from the file of the layout
instead, so the viewer hides those items on the map and displays them again on
the other layouts: it is the choice to make when a point on the border would be
read as a real location. With "Keep them where they are", the raw coordinates
are written. A collection of less than fifty points is kept as it is in every
case, where a distant point is a part of the subject and not an outlier.

#### Base map

A base map is displayed behind the map layout, so the images are read on a real
map and not on an empty canvas. It follows the zoom and the pan of the canvas
exactly: the generator writes the points in the Web Mercator projection and
inscribes them in a square window, whose two corners are written as two anchor
rows of the file, so the map is never distorted. The collection therefore
occupies a square of the canvas instead of filling it, which is the price of a
map that can be read.

The tiles are those of OpenStreetMap by default. **Their usage policy forbids
the traffic of a public site**: set the option "Tiles of the base map" to a
self-hosted or a licensed tile server, and the option "Attribution of the base
map" to the credit that its licence requires; the attribution is a plain text,
not html, so a link cannot be written in it. The option "Opacity of the base
map" fades the tiles, so the images stay readable above them. The option "Hide
the items without coordinates" makes the items absent from the file of the
layout fully transparent while the map layout is displayed, instead of the ring
of dimmed images that the viewer lays out around the map; the items excluded by
the current filter are hidden the same way on this layout. These four options
are a presentation only and do not require a regeneration of the cache.

The canvas of the viewer is made transparent so the map shows through it, and
the page carries the background colour of the canvas itself. The map takes no
click: every interaction belongs to the canvas.

An item without point is absent from the file of the layout, so the viewer hides
it on the map, and displays it again on the other layouts. When the module
Mapping is not active, or when no displayed item has a point, neither the file
nor the button is built: nothing changes in the visualization. As any other
option of the data, changing it requires a regeneration of the cache, which is
automatic for a block.

### Options of the site (resource page block)

The resource page block has no form of its own: its options are the site
settings of the same names (in the admin, go to the site, then "Settings", group
"Vikus Viewer"). They are the options of the block above, except the query, the
title, the presentation and the events of the timeline, that are built from the
item set itself. The defaults are the same as the ones of the block.

### Cache of an item set

The cache of an item set is stored in `files/vikus/s{site id}-r{item set id}/`,
because the visible items and the links depend on the site. It is built lazily:
the first display of the page of the item set launches the background job and
displays "The visualization is being generated."; the next displays serve the
cache. A second job is never launched while a first one is queued or running for
the same item set.

Because the items of an item set change without the item set itself being saved,
the cache is not invalidated automatically: as for a block, use the button
"Regenerate" of the full page of the viewer, displayed to the users who can edit
the item set. A visualization that stays on "The visualization is being generated."
(a job killed without updating its status) is regenerated automatically one hour
later, or immediately with the button "Regenerate". The cache is deleted with
its item set or its site, and it is rebuilt when a site setting of the viewer is
modified.

### Cache, background job and "Regenerate"

The block does not query the database when a visitor displays the page: the data
are prepared once and stored in `files/vikus/b{block id}/` (`data.csv`,
`timeline.csv`, `info.md`, `meta.json` and `sprites/`).

* When the page is saved and an option of the **data** was modified (query,
  properties, source of the images, link, presentation), the published cache is
  deleted and a background job rebuilds it. Until it ends, the page displays
  "The visualization is being generated.". The options of presentation (title,
  colors, columns, height) are applied immediately, without any job.
* Items added or modified after the generation are **not** included
  automatically. To refresh the visualization, check "Regenerate on save" in the
  form of the block and save the page. The current visualization stays available
  while the job runs. The link "Full screen" under the viewer opens it alone in
  a new tab.
* The job can be stopped from the admin page of the jobs. The cache of a block
  is deleted with its block or its page, and `files/vikus/` is removed when the
  module is uninstalled.

### IIIF image server (optional)

When [IiifServer] is installed, the detailed images (1024 px and 4096 px) are
requested from the image server it declares (the module [ImageServer], or an
external server such as Cantaloupe set in the configuration of [IiifServer],
"Media API url") through the IIIF image api
(`.../full/!1024,1024/0/default.jpg`), which avoids downloading the original
files. An image smaller than the requested size is asked at its full size
(`max`) when its dimensions are known, since the servers of the Image API 3
refuse to enlarge an image; when they are unknown, the prefix `^` of the api
3 is used. The version of the api and the identifier of the image follow the
settings of [IiifServer]. Media that are not images fall back to the derivatives
`large` and `original` of Omeka, and so does the whole module without [IiifServer]
or with the source "omeka". The manifests of IIIF Presentation are not used: the
viewer does not read them.

### Digital objects (optional)

When the module [DigitalObject] is installed, an item without media may still be
displayed: the first digital object linked to it through the properties
configured in that module (`dcterms:hasFormat` and `dcterms:hasPart` by default)
and having thumbnails is used as its image, exactly as a media would be (sprite,
detailed images, IIIF image server). A media of the item always takes
precedence, and an item without media and without digital object is skipped as
before. When the instance holds at least one digital object, the item query
cannot filter on the presence of an image any more, so all the items of the site
are read and the ones without image are skipped while reading.


Size of a visualization
-----------------------

The thumbnails are packed in atlases of 2048×2048 pixels, so about 72 sprites of
256 px by atlas for portrait images (49 for square ones), and each atlas is a
jpeg of around one megabyte that the graphic card keeps uncompressed, as a
texture of 16 MB. A measured visualization of 3436 posters means 48 atlases,
53 MB downloaded and around 770 MB of memory of the graphic card: the memory of
the graphic card, not the network, is the real limit. The upstream demos hold
around 1700 items.

| Number of items | Atlases | Download | Graphic memory | Result                                      |
|-----------------|---------|----------|----------------|---------------------------------------------|
| ≤ 2000          | ≤ 30    | ≤ 35 MB  | ≈ 480 MB       | comfortable everywhere                      |
| 2000–5000       | 30–70   | 35–80 MB | 0.5–1.1 GB     | slow on laptops and phones                  |
| > 5000          | > 70    | > 80 MB  | > 1.1 GB       | not usable on most devices                  |
| > 10000         | —       | —        | —              | refused: the job stops at the hard limit    |

The option "Size of the sprites" set to 128 px divides the number of atlases, so
the download and the memory of the graphic card, by four: a visualization of
5000 items then costs around 280 MB of memory of the graphic card.

Server recommendations
----------------------

The viewer downloads one csv file and some jpeg atlases, so:

* enable gzip (or brotli) on `text/csv`, `application/json` and `text/markdown`:
  the csv of a large collection is compressed by ten;
* enable HTTP/2: the viewer requests many small files (js, css, fonts, atlases)
  in parallel;
* let the browser cache `files/`, as for any other file of Omeka.


Limits of the first version
---------------------------

* Only the primary image of an item is displayed (no multi-page item).
* No similarity layout (t-SNE/UMAP): only the layout by year, the group
  layouts by property and the map layout. The map uses the same csv mechanism
  as the similarity layouts, so such a layout could be added the same way.
* Only in a page of a site, via a block (no resource page block for item sets).
* The viewer spreads the images over the whole range of years, so a collection
  spread over several centuries with few items by year displays very small
  thumbnails until the visitor zooms in. It is designed for collections with
  many items by year.
* An item whose thumbnail cannot be read keeps its row in the csv, so it is
  displayed as a white tile on the canvas, and its id is listed in the log of
  the job.
* With a remote file store (S3 and similar), the thumbnails are fetched by url
  without any timeout: a host that hangs stalls the job until the process is
  stopped.


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

* "The visualization is being generated." stays displayed
  The background job did not end. Check the page of the jobs in the admin and
  its log.
* "No item with an image."
  The query returns no item with a primary media with thumbnails. Check the
  query and the thumbnails of the items.
* The canvas stays empty
  Check that `files/vikus/b{block id}/sprites/` contains atlases, and the
  console of the browser for a failed request.
* The thumbnails are blurry
  The derivative `large` of the media is too small or missing; rebuild the
  thumbnails of the files.
* Some tiles are white
  The thumbnails of these items are not readable (missing file, unsupported
  format); their ids are listed in the log of the job.
* The job does not end and does not log anything
  With a remote file store, it may be waiting for a thumbnail fetched by url,
  without timeout. Stop the job and check the availability of the store.
* The viewer shows raw templates (`{{ entry.name }}`) or its assets answer 500
  An `.htaccess` shipped by the viewer is present in `asset/vendor/vikus-viewer/`
  and uses `mod_expires`, that is not enabled on the server. Remove that file.

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

* Copyright Daniel Berthereau, 2026 (see [Daniel-KM])
* Copyright Christopher Pietsch for [Vikus Viewer]


[Vikus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus
[Leaflet]: https://leafletjs.com
[Omeka S]: https://omeka.org/s
[Vikus Viewer]: https://github.com/cpietsch/vikus-viewer
[Musée de Bretagne]: https://www.collections.musee-bretagne.fr/s/fr/page/vikus-affiches
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[IiifServer]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[DigitalObject]: https://gitlab.com/Daniel-KM/Omeka-S-module-DigitalObject
[Mapping]: https://github.com/omeka-s-modules/Mapping
[ImageServer]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Vikus.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus/-/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vikus/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://opensource.org/licenses/MIT
[Job Queue]: https://gitlab.com/Daniel-KM/Omeka-S-module-JobQueue
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
