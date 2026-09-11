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
php anno.php > page.json      # a IIIF AnnotationPage with xywh targets
php anno.php -html > page.html   # the same rectangles drawn over the page
```

Two things it handles:

- **Re-anchoring.** `anchor_annotation()` locates the annotation by its `TextQuoteSelector` (prefix/exact/suffix, matched on whitespace-normalised text) and uses the `TextPositionSelector` only as a hint, trying it as both a character and a byte offset. This is what lets an annotation made against one OCR engine's text be placed in another's — the quote is the portable part, the offsets are not.
- **Line breaks.** `span_to_regions()` returns one rectangle per line rather than one bounding box for the whole span, so a name split over two lines (or two columns) highlights correctly. Words are grouped by the `line` block that contains them, falling back to clustering on vertical position when the page has no line blocks.

Where a page has no `word` blocks — Mistral only gives paragraph level blocks — the highlight is as coarse as the blocks are. The useful move there is to anchor the annotation into a *word level* OCR of the same page and use those coordinates.
