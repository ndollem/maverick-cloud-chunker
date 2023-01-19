<?php

use Google\CloudFunctions\FunctionsFramework;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;

// Register the function with Functions Framework.
// This enables omitting the `FUNCTIONS_SIGNATURE_TYPE=http` environment
// variable when deploying. The `FUNCTION_TARGET` environment variable should
// match the first parameter.
FunctionsFramework::http('maverickChunker', 'maverickChunker');

function maverickChunker(ServerRequestInterface $request): ResponseInterface
{
    $msg = "Bad Request";

    if ($request->getMethod() == "POST") {
        $data = $request->getParsedBody();
        $msg = "Missing parameter content";
    
        if(isset($data['content'])){
            $chunker = new maverickChunkerClass($data);

            return new Response(
                200,
                ['content-type' => 'application/json'],
                json_encode([
                    "msg" => "Success",
                    "data" => $chunker->parseNews($data)
                ])
            );
        }
    }
    
    return new Response(
        400,
        ['content-type' => 'application/json'],
        json_encode([
            "msg" => $msg
        ])
    );
}

class maverickChunkerClass
{

    //private $content;
    private $bg_color_order;
    private $bg_color_opt;
    private $fs_opt;
    private $allowedEl;
    private $max_char = 900;
    private $additional_char_per_paragraf = 30; 
    private $site_origin = 'trstdly.com';
    
    function __construct()
    {
        //$this->content = ($rawContent) ? $rawContent : false;

        $this->bg_color_order = -1;

        //allowed main element to be checked on chunk process
        $this->allowedEl = ['p', 'ol', 'ul', 'img', 'figure', 'h2'];
        
        //adjust this according to each site provided UI Style
        $this->bg_color_opt = [
            'bg-light-0', 'bg-light-1', 'bg-light-2', 'bg-light-3', 'bg-light-4'
        ];

        $this->fs_opt = [
            'vh-text-xs'=> ['min'=> 501, 'max'=> 10000],
            'vh-text-sm'=> ['min'=> 401, 'max'=> 500],
            'vh-text-md'=> ['min'=> 301, 'max'=> 400],
            'vh-text-lg'=> ['min'=> 171, 'max'=> 300],
            'vh-text-xl'=> ['min'=> 61, 'max'=> 170],
            'vh-text-2xl'=> ['min'=> 1, 'max'=> 60],
        ];
    }

    public function parseNews($row)
    {
        $this->site_origin = isset($row['origin']) ? $row['origin'] : $this->site_origin;
        $news = collect();

        $news = $news->merge($this->parseDom($this->replace_fig_with_p($row['content'])));
        //print_r($news);
        $news = $this->transformElement($news);

        return $news;
    }

    private function replace_fig_with_p($text)
    {
        return str_replace("figure", "p", $text);
    }

    private function parseDom($data)
    {
        $dom = $this->loadDOM($data);
        $domx = new DOMXPath($dom);
        //$entries = $domx->evaluate("//p");
        $entries = $domx->evaluate("*/*");
        $arr = array();
        foreach ($entries as $entry) {
            //print_r($entry);
            if(in_array($entry->nodeName, $this->allowedEl)){
                $arr[] = $entry;
            }
        }

        return $arr;
    }

    private function loadDOM($data, $opt=0)
    {
        $dom = new DOMDocument();
        $dom->loadHTML($data, $opt);

        return $dom;
    }

    private function transformElement(Collection $elm): array
    {
        $elm->transform(function($item, $key) {
            //print_r($item);
            
            $type = $this->get_type_basedOnNodes($item);

            $return = [
                'type' => $type,
                'chars' => ($type=='img') ? 0 : strlen($item->textContent),
                'words' => ($type=='img') ? 0 : str_word_count(strip_tags($this->elmToHtml($item))),
                'content' => ($type=='img') ? null : strip_tags($this->elmToHtml($item)),
                'rawContent' => $this->elmToHtml($item),
                'attributes' => $this->get_attributes($item),
            ];

            //check current text if it is part of image caption (works on trstdly)
            if(
                $return['type']=='text' && 
                count($return['attributes']) > 0 && 
                isset($return['attributes']['class']) && 
                $return['attributes']['class']=='content-image-caption'
            ) 
            {
                $return['type'] = 'img-caption';
            }

            //skip no value on paragraph
            if($return['chars']>5 || $return['type']=='img'){
                return $return;
            }
        });

        //Add template information
        return $this->suit_up_templates($elm->filter()->all());

    }

    private function suit_up_templates(array $rows): array
    {
        $data = [];
        $skip_next = 0;
        $curr_char = 0;
        $next_index = 0;
        //print_r($rows); exit;
        $rows = array_values($rows);
        
        foreach($rows as $index=>$row)
        {
            //check if need to skip this itteration 
            if($skip_next > 0){
                $skip_next--;
                $curr_char = 0;
                continue;
            }

            $templ_conf = [];
            //setup background color theme order
            $this->bg_color_order = 0; //($this->bg_color_order >= 3) ? 0 : ($this->bg_color_order+1);
            $templ_conf['bg_theme'] = $this->bg_color_opt[$this->bg_color_order];
            
            switch ($row['type']) {
                case 'title':
                    //CASE : -- if the current type is title
                    if(isset($rows[$index+1]) && $rows[$index+1]['type']=='img'){
                        //combine next row data of image into this row
                        $row['rawContent'] .= $rows[$index+1]['rawContent'];
                        $row['type'] = 'title-img';
                        $row['attributes'] = $rows[$index+1]['attributes'];
                        //skip for next itteration
                        $skip_next++;

                        //CASE : -- if the next row after image is a caption
                        if(isset($rows[$index+2]) && $rows[$index+2]['type']=='img-caption'){
                            //combine next row data of image caption into this row
                            $row['attributes']['caption'] = $rows[$index+2];
                            //skip for next itteration
                            $skip_next++;
                        }
                    }else{
                        extract($this->populate_text_content($rows, $index));
                        $row['type'] = 'text';
                    }
                    break;

                case 'img':
                    //CASE : -- if next row is part of image caption
                    if(isset($rows[$index+1]) && $rows[$index+1]['type']=='img-caption'){
                        //combine next row data of image caption into this row
                        $row['attributes']['caption'] = $rows[$index+1];
                        //skip for next itteration
                        $skip_next++;
                    }
                    break;

                //case 'text' || 'title' || 'list':
                default:
                    //CASE : -- if current text still not reaching max total char per screen
                    extract($this->populate_text_content($rows, $index));
                    break;

                
            }

            //CASE : -- setup font size based on the length of text
            $templ_conf['fontSize_class'] = $this->get_fontSize($row['chars']);

            $row['template'] = $templ_conf;
            $data[] = $row;
        }

        return $data;
    }

    private function populate_text_content($rows, $index)
    {
        $row = $rows[$index];

        if($row['chars'] < $this->max_char)
        {
            //reset all looping variables
            $curr_char = $row['chars'];
            $next_index = $skip_next = 0;

            //loop while current char still not exceeding the max char limit
            while($curr_char < $this->max_char)
            {
                //plus 1 for skipping next $row loop
                $next_index = $index+$skip_next+1;

                //check if the next index is exist and 
                //if we add next index it will not exceeding max char limit
                if(
                    isset($rows[$next_index]) && 
                    ($rows[$next_index]['type']=='text' || $rows[$next_index]['type']=='title' || $rows[$next_index]['type']=='list') &&
                    ($curr_char + $rows[$next_index]['chars']) < $this->max_char
                ){
                    //CASE : -- if current index is Title text type and if the next row after title is an image
                    if(
                        $rows[$next_index]['type']=='title' && 
                        $rows[$next_index + 1]['type']=='img'
                    ){
                        //then stop the itteration here, in order to make the title & image to be populated in 1 screen
                        $curr_char = $this->max_char+1;
                    }else{
                        //combine with next row data (as long it also text)    
                        $row['content'] .= ' '.$rows[$next_index]['content'];
                        $row['rawContent'] .= $rows[$next_index]['rawContent'];
                        $row['chars'] = $curr_char = strlen($row['content']);
                        $row['words'] = str_word_count(strip_tags($row['content']));
                        $skip_next++;
                        $curr_char = strlen($row['content']) + ($this->additional_char_per_paragraf*$skip_next);
                        //$row['curr_char'] = $curr_char;
                        //$row['skip_next'] = $skip_next;
                    }
                }else{
                    //reset counter
                    $curr_char = $this->max_char+1;
                }
            }
        }

        return [
            'row' => $row,
            'skip_next' => $skip_next
        ];
    }

    private function get_fontSize($charLength=0): string
    {
        $fs_class = '';
        foreach($this->fs_opt as $index=>$range){
            if($charLength>=$range['min'] && $charLength<=$range['max']) $fs_class = $index;
        }

        return $fs_class;
    }

    /**
     * check the element nodename to decide the type of it
     */
    private function get_type_basedOnNodes($item): string
    {
        $type = 'text';
        $elm = $item->childNodes;
        //print_r($this->get_attributes($item));

        if($item->nodeName=='h2'){
            $type = 'title';
        }elseif($item->nodeName=='ul' || $item->nodeName=='ol'){
            $type = 'list';
        }else{
            //consider node name is a p (paragraf), check for child element 
            for ($i = 0; $i < $elm->length; ++$i) {
                //print_r($elm->item($i));
                $nodeName = $elm->item($i)->nodeName;
                if($nodeName == 'img') $type = 'img';
                
                if(
                    //specific to handle image caption from newshub cms
                    ($nodeName == 'strong' || $nodeName == 'h2') && //the child node contain h2 or strong
                    $elm->length==1 //the node only have 1 child element
                ){
                    $type = 'title';
                }elseif(
                    //specific to handle image caption from merdeka cms
                    $nodeName == 'em' && //the child node contain em
                    $this->site_origin == 'merdeka.com' && //the request coming from merdeka
                    $elm->length==1 //the node only have 1 child element
                ){
                    $type = 'img-caption';
                }
            }
        }
        
        return $type;
    }

    /**
     * Check and populate all attributes on the element
     */
    private function get_attributes(DOMElement $dom): array
    {
        $attr = [];
        
        $elm = $dom->firstChild->nodeName=='img' ? $dom->firstChild : $dom;

        for ($i = 0; $i < $elm->attributes->length; ++$i) {
            $name = $elm->attributes->item($i)->name;
            $attr[$name] = $elm->attributes->item($i)->value;
        }

        return $attr;
    }

    /**
     * save the element in the complete raw format
     */
    private function elmToHtml(DOMElement $dom): string
    {
        return $dom->ownerDocument->saveHtml($dom);
    }

    /**
     * itterate all the santences of paragraph
     */
    private function getSentences($data)
    {
        $sentence = explode('.', $data);

        $sentence_array = [];
        foreach($sentence as $s) {
            if($s != '') {
                $sentence_array[] = trim($s);
            }
        }

        return $sentence_array;
    }
}
