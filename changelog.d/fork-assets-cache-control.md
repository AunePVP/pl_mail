**The page answers clicks sooner on the first visit of the day.** Scripts and styles under
`/assets` are now sent with `Cache-Control: immutable`: their names already carry a content hash,
so a browser may keep them for good instead of asking after each of ~180 files again every morning,
and again after every update. Until it had them all, the page showed mail but answered no click.
