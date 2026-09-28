# OCR format

The goal is to have a simple, human readable OCR output format that makes it easy to add (primarily) text-based annotations. For example, we take DjVu XML, hOCR, and Mistral JSON and output  JSON in the same format. We then have simple tools to render that JSON as HTML (so we can see block layout, etc.).

Basic designed idea (after chatting with Claude) is to have the text for each page stored as a single chunk of text, and have an array of “blocks” of various types that have normalised coordinates with respect to the page (i.e., scaled [0-1] based on page size). If a block contains text, we refer to that text using a character position span in the single text chunk (e.g., [23, 34]. That way the OCR text only appears once. Blocks are not nested (i.e., there is no paragraph, line, word hierarchy), they are treated as a simple list.

Initially we have code to transform some outputs to the common JSON. Want to add code to handle adding annotations at various levels, and explore ways annotations can be “carried over” between the output of different OCR engines. For example, if we have DjVu coordinates for OCR text, plus some annotations (such as strings that represent an entity of interest) and we then use Mistral (or similar) to redo the OCR, how do we apply those annotations to the new OCR text?


## Character offsets

Block `span` values are **character (Unicode codepoint) offsets** into the page text, not byte offsets — the converters all count with `mb_strlen`. This matters because plain PHP string functions (`strlen`, `strpos`, `substr`) count bytes, so any tool that produces annotations that way (taxonfinder-php, for example) will report offsets that drift as soon as the page contains a non-ASCII character. On page 3 of the `Amphibianreptil9A` sample, twelve em dashes in a table are enough to push the two apart by 24.

Use `mb_substr` / `mb_strpos` when working with spans, or re-anchor using the annotation's `TextQuoteSelector` (see `anno-map.php`).

## Mapping text annotations to coordinates

`anno-map.php` maps a character span onto page coordinates, and `anno.php` demonstrates it:

```
php anno.php > page.json         # a IIIF AnnotationPage with xywh targets
php anno.php -html > page.html   # the same rectangles drawn over the page
```

It runs against `examples/Amphibianreptil9A_djvu-common.json` page 3 by default;
name another common JSON and page to place the same annotations on a different
OCR of the same page:

```
php anno.php examples/Amphibianreptil9A-mistral-common.json 3
```

Two things it handles:

- **Re-anchoring.** `anchor_annotation()` locates the annotation by its `TextQuoteSelector` (prefix/exact/suffix, matched on whitespace-normalised text) and uses the `TextPositionSelector` only as a hint, trying it as both a character and a byte offset. This is what lets an annotation made against one OCR engine's text be placed in another's — the quote is the portable part, the offsets are not.
- **Line breaks.** `span_to_regions()` returns one rectangle per line rather than one bounding box for the whole span, so a name split over two lines (or two columns) highlights correctly. Words are grouped by the `line` block that contains them, falling back to clustering on vertical position when the page has no line blocks.

Where a page has no `word` blocks — Mistral only gives paragraph level blocks — the highlight is as coarse as the blocks are. The useful move there is to anchor the annotation into a *word level* OCR of the same page and use those coordinates.

## Placing strings on a page

Sometimes all we know is that a string occurs on a page (e.g. a taxonomic name from BHL's name index, or a place name), with no offsets or context. `string-map.php` finds the string in the page text, and `strings.php` turns a list of them into annotations. The workflow is:

1. get the OCR for a BHL item and convert it to common JSON;
2. get a list of the strings on each page, from BHL or elsewhere;
3. place them on the page with `strings.php`.

The input is a TSV, `page<TAB>string[<TAB>source[<TAB>locator]]`:

- `page` is the 0-based index into `pages` in the common JSON;
- `source` is where the string comes from, typically a dataset DOI or other URI;
- `locator` identifies the entry in that source, such as the URL or LSID of a taxonomic name, or a row fragment identifier (`#row=12`) for a CSV file.

`source` and `locator` are copied to the output as they are. If a row has no source, the input file is the source (its file name) and the row's line number is the locator (`#row=n`, counting any header line, as RFC 7111 does for CSV). Output is a IIIF AnnotationPage by default:

```
php strings.php lepidopteraofcey01moor_hocr-common.json examples/lepidopteraofcey01moor-strings.tsv > strings.json
```

or, with `-tsv`, a table for loading into a database, one row per match:

```
php strings.php -tsv lepidopteraofcey01moor_hocr-common.json examples/lepidopteraofcey01moor-strings.tsv > strings.tsv
```

| column | |
|---|---|
| `page`, `string`, `source`, `locator` | from the input |
| `width`, `height` | the page size in the OCR, to check against the image the IIIF canvas is built from |
| `text` | what the OCR actually says |
| `start`, `end` | character offsets of `text` in the page text (as for block spans) |
| `prefix`, `suffix` | the text either side, 32 characters by default (`-context=n`) |
| `xywh` | the region on the page, in the same pixels as `width` and `height`; a match that runs over a line break has one rectangle per line, separated by `;` |
| `distance` | edit distance between `string` and `text`, 0 for an exact match |

A string that isn't found still gets a row, with the match columns empty, so every input row is accounted for. Tabs, line breaks and backslashes in the text are written as `\t`, `\n`, `\r` and `\\`, which PostgreSQL's `COPY` and MySQL's `LOAD DATA` read by default. There is no canvas column: which canvas a page belongs to is decided when the rows are loaded. The JSON output, which is there for trying things out in IIIF viewers, does need canvases; they are made up unless you give a template, e.g. `-canvas='https://example.org/canvas/p{n}'`, where `{page}` is the 0-based page index and `{n}` the 1-based number. A summary of what was matched (and what wasn't) goes to STDERR.

Matching is on tokens, ignoring case and punctuation, with the spaces squeezed out, trying windows one token shorter and longer than the string. So "ABARATHA" matches "Abaratha", and strings the OCR has split, hyphenated across a line, or run together are still found. If a string has exact matches on the page, all of them are used. If not, the closest matches within an edit distance of 30% of the string's length are used instead (so the running head "HESPERIUD4i" is found for "Hesperiidae"). These annotations have a second body recording what the OCR actually says. Where strings overlap, the one with more tokens wins, so "Xus" is only annotated where it isn't part of "Xus aus".

To check the matches by eye, `-html` draws them over each page (exact matches in red, approximate ones in orange), and `-image` puts the page images underneath, using the same `{page}` and `{n}` placeholders:

```
php strings.php -html \
  -image='https://iiif.archive.org/iiif/lepidopteraofcey01moor${page}/full/1000,/0/default.jpg' \
  lepidopteraofcey01moor_hocr-common.json examples/lepidopteraofcey01moor-strings.tsv > strings.html
```

For Internet Archive items, use the IIIF image server as above, whose page numbers match the hOCR's; the `/page/n{N}` URLs can be out by one. The same preview is available for `anno.php` with `-html`.
