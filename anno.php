<?php

require_once(dirname(__FILE__) . '/anno-map.php');

//----------------------------------------------------------------------------------------
// Demo: take text annotations made against a page's text (here the output of taxonfinder-php)
// and map them onto the word coordinates in the common OCR JSON, so they can be shown as
// highlights in a IIIF viewer.
//
// Usage: php anno.php [-html] > out.json
//

$filename = 'Amphibianreptil9A_djvu-common.json';
$page_number = 3;

// The canvas these annotations will hang off
$canvas = 'https://example.org/iiif/Amphibianreptil9A/canvas/p' . ($page_number + 1);

$html_output = in_array('-html', $argv);

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
	echo annotation_preview_html($page, $debug);
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

//----------------------------------------------------------------------------------------
// Quick visual check: draw the rectangles over a blank sheet the shape of the page
function annotation_preview_html($page, $debug)
{
	$html = '<html><head><meta charset="utf-8"><style>
body { background:rgb(242,242,242); font-family:sans-serif; }
.page { position:relative; margin:1em auto; max-width:1000px; background:white; border:1px solid rgb(192,192,192); }
.noimage { padding-top:' . round(((int)$page->height / (int)$page->width) * 100, 3) . '%; }
.word { position:absolute; background:rgb(224,224,224); }
.hit { position:absolute; background:rgba(255,0,0,0.35); outline:1px solid red; }
</style></head><body><div class="page"><div class="noimage"></div>';

	foreach ($page->blocks as $block)
	{
		if ($block->type != 'word')
		{
			continue;
		}

		$html .= '<div class="word" style="' . block_style($block->bbox) . '"></div>';
	}

	foreach ($debug as $row)
	{
		foreach ($row->regions as $region)
		{
			$html .= '<div class="hit" title="' . htmlspecialchars($row->value . ' [' . $region->text . ']')
				. '" style="' . block_style($region->bbox) . '"></div>';
		}
	}

	$html .= '</div></body></html>';

	return $html;
}

//----------------------------------------------------------------------------------------
function block_style($bbox)
{
	return 'left:' . round($bbox[0] * 100, 3) . '%;'
		. 'top:' . round($bbox[1] * 100, 3) . '%;'
		. 'width:' . round(($bbox[2] - $bbox[0]) * 100, 3) . '%;'
		. 'height:' . round(($bbox[3] - $bbox[1]) * 100, 3) . '%;';
}

?>
