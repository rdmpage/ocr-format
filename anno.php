<?php

require_once(dirname(__FILE__) . '/anno-map.php');

//----------------------------------------------------------------------------------------
// Demo: take text annotations made against a page's text (here the output of taxonfinder-php)
// and map them onto the word coordinates in the common OCR JSON, so they can be shown as
// highlights in a IIIF viewer.
//
// Usage: php anno.php [-html] [<common.json> [<page>]] > out.json
//

$filename = dirname(__FILE__) . '/examples/Amphibianreptil9A_djvu-common.json';
$page_number = 3;

$html_output = in_array('-html', $argv);

$files = array_values(array_filter(array_slice($argv, 1), function($argument)
{
	return substr($argument, 0, 1) != '-';
}));

if (count($files) > 0)
{
	$filename = $files[0];
}

if (count($files) > 1)
{
	$page_number = (int)$files[1];
}

if (!file_exists($filename))
{
	fwrite(STDERR, "Can't read '" . $filename . "'\n");
	exit(1);
}

// The canvas these annotations will hang off
$canvas = 'https://example.org/iiif/' . basename($filename, '.json')
	. '/canvas/p' . ($page_number + 1);

$obj = json_decode(file_get_contents($filename));
$page = $obj->pages[$page_number];

$annotation_json = <<<'ANNOTATIONS'
[
    {
        "type": "Annotation",
        "body": {
            "type": "TextualBody",
            "purpose": "identifying",
            "value": "Proctoporus"
        },
        "target": {
            "selector": [
                {
                    "type": "TextQuoteSelector",
                    "prefix": "ecimens of all known species\nof ",
                    "exact": "Proctoporus",
                    "suffix": ". Because only two specimens (on"
                },
                {
                    "type": "TextPositionSelector",
                    "start": 1288,
                    "end": 1299
                }
            ]
        },
        "formal": true
    },
    {
        "type": "Annotation",
        "body": {
            "type": "TextualBody",
            "purpose": "identifying",
            "value": "Proctoporus"
        },
        "target": {
            "selector": [
                {
                    "type": "TextQuoteSelector",
                    "prefix": "adult\nmale and one juvenile) of ",
                    "exact": "Proctoporus",
                    "suffix": " cliasqui , were ex-\namined, we "
                },
                {
                    "type": "TextPositionSelector",
                    "start": 1365,
                    "end": 1376
                }
            ]
        },
        "formal": true
    },
    {
        "type": "Annotation",
        "body": {
            "type": "TextualBody",
            "purpose": "identifying",
            "value": "Proctoporus machupicchu"
        },
        "target": {
            "selector": [
                {
                    "type": "TextQuoteSelector",
                    "prefix": "ents (mm) of three specimens of ",
                    "exact": "Proctoporus machupicchu",
                    "suffix": " sp. nov. and the addition of Fi"
                },
                {
                    "type": "TextPositionSelector",
                    "start": 1559,
                    "end": 1582
                }
            ]
        },
        "formal": true,
        "statements": {
            "verbatim": "sp. nov.",
            "terms": [
                "sp. nov."
            ],
            "start": 1583,
            "end": 1591
        }
    },
    {
        "type": "Annotation",
        "body": {
            "type": "TextualBody",
            "purpose": "identifying",
            "value": "Proctoporus machupicchu"
        },
        "target": {
            "selector": [
                {
                    "type": "TextQuoteSelector",
                    "prefix": "lume 9 | Number 1 | e96\nResults\n",
                    "exact": "Proctoporus machupicchu",
                    "suffix": " sp. nov.\nurn:lsid:zoobank.org:a"
                },
                {
                    "type": "TextPositionSelector",
                    "start": 2724,
                    "end": 2747
                }
            ]
        },
        "formal": true,
        "statements": {
            "verbatim": "sp. nov.",
            "terms": [
                "sp. nov."
            ],
            "start": 2748,
            "end": 2756
        },
        "identifiers": [
            {
                "scheme": "zoobank",
                "type": "act",
                "value": "urn:lsid:zoobank.org:act:216381E4-4C4B-4C3C-99AE-0DCEFEC45352",
                "verbatim": "urn:lsid:zoobank.org:act:216381E4-4C4B-4C3C-99AE-0DCEFEC45352",
                "start": 2757,
                "end": 2818
            }
        ]
    }
]
ANNOTATIONS;

$annotations = json_decode($annotation_json);

$output = [];
$debug = [];

foreach ($annotations as $index => $annotation)
{
	$anchor = anchor_annotation($page->text, $annotation);

	if (!$anchor)
	{
		fwrite(STDERR, "Couldn't anchor annotation " . $index . "\n");
		continue;
	}

	$regions = span_to_regions($page, $anchor->start, $anchor->end);

	$id = 'https://example.org/annotation/' . $page_number . '/' . $index;

	foreach (iiif_annotation($annotation, $canvas, $regions, $id) as $iiif)
	{
		$output[] = $iiif;
	}

	$row = new stdclass;
	$row->value = $annotation->body->value;
	$row->anchored_by = $anchor->method;
	$row->recorded_span = [$annotation->target->selector[1]->start, $annotation->target->selector[1]->end];
	$row->actual_span = [$anchor->start, $anchor->end];
	$row->regions = $regions;

	$debug[] = $row;
}

if ($html_output)
{
	echo annotation_preview_html([annotation_preview_page($page, $debug)]);
}
else
{
	fwrite(STDERR, print_r($debug, true));

	$page_annotations = new stdclass;
	$page_annotations->{'@context'} = 'http://iiif.io/api/presentation/3/context.json';
	$page_annotations->id = 'https://example.org/annotation/page/' . $page_number;
	$page_annotations->type = 'AnnotationPage';
	$page_annotations->items = $output;

	echo json_encode($page_annotations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

?>
