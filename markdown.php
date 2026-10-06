<?php

// Based on a conversation with ChatGPT 
// https://chatgpt.com/share/6a9992d0-0e88-83ed-90e8-07c5a25310fd

require_once (dirname(__FILE__) . '/vendor/autoload.php');

//----------------------------------------------------------------------------------------
function dom_to_text($node)
{
    $text = '';

    foreach ($node->childNodes as $child) {

        if ($child->nodeType == XML_TEXT_NODE) {
            $text .= $child->nodeValue;
            continue;
        }

        if ($child->nodeType != XML_ELEMENT_NODE) {
            continue;
        }

        $name = strtolower($child->nodeName);

        switch ($name) {

            case 'p':
            case 'div':
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $text .= dom_to_text($child) . "\n";
                break;

            case 'br':
                $text .= "\n";
                break;

            case 'li':
                $text .= dom_to_text($child) . "\n";
                break;

            case 'tr':
                $cells = array();

                foreach ($child->childNodes as $cell) {
                    if (
                        $cell->nodeType == XML_ELEMENT_NODE
                        && in_array(strtolower($cell->nodeName), array('td', 'th'))
                    ) {
                        $cells[] = trim(dom_to_text($cell));
                    }
                }

                $text .= implode(" ", $cells);
                break;

            case 'table':
                $text .= dom_to_text($child) . "\n";
                break;

            case 'td':
            case 'th':
                // Normally handled by <tr>
                $text .= dom_to_text($child);
                break;

            case 'a':
                // Keep link text, discard URL
                $text .= dom_to_text($child);
                break;

			// we will eat img tags
            case 'img':
                // Perhaps retain alt text
                if ($child->hasAttribute('alt')) {
                    $text .= $child->getAttribute('alt');
                }
                break;
                
            default:
                // strong, em, span, code, etc.
                $text .= dom_to_text($child);
                break;
        }
    }

    return $text;
}

//----------------------------------------------------------------------------------------
function markdown_to_text($markdown)
{
    $parsedown = new Parsedown();

    // Treat any raw HTML in the Markdown as literal text, so that strings such as
    // SICI-style DOIs (e.g. 254<0001:NWPSAP>2.0.CO;2) are not parsed as tags and lost
    $parsedown->setMarkupEscaped(true);

    $html = $parsedown->text($markdown);

    $dom = new DOMDocument();

    // Avoid DOMDocument complaining about HTML fragments
    libxml_use_internal_errors(true);

    $dom->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();

    return trim(dom_to_text($dom));
}

?>
