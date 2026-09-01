# OCR format

The goal is to have a simple, human readable OCR output format that makes it easy to add (primarily) text-based annotations. For example, we take DjVu XML, hOCR, and Mistral JSON and output  JSON in the same format. We then have simple tools to render that JSON as HTML (so we can see block layout, etc.).

Basic designed idea (after chatting with Claude) is to have the text for each page stored as a single chunk of text, and have an array of “blocks” of various types that have normalised coordinates with respect to the page (i.e., scaled [0-1] based on page size). If a block contains text, we refer to that text using a character position span in the single text chunk (e.g., [23, 34]. That way the OCR text only appears once. Blocks are not nested (i.e., there is no paragraph, line, word hierarchy), they are treated as a simple list.

Initially we have code to transform some outputs to the common JSON. Want to add code to handle adding annotations at various levels, and explore ways annotations can be “carried over” between the output of different OCR engines. For example, if we have DjVu coordinates for OCR text, plus some annotations (such as strings that represent an entity of interest) and we then use Mistral (or similar) to redo the OCR, how do we apply those annotations to the new OCR text?

