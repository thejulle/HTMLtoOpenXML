<?php

namespace HTMLtoOpenXML;

class Parser
{

    private $_cleaner;
    private $_processProperties;
    private $_listIndex = 1;
    private $_listLevel = 0;
    private $_openXml = '';
    private $_pStyle = null;
    private $_rStyle = null;

    public function __construct() {
        $this->_cleaner = new Scripts\HTMLCleaner;
        $this->_processProperties = new Scripts\ProcessProperties;
    }

    /**
     * Converts HTML to RTF.
     *
     * @param string $html the HTML formatted input string
     * @param bool   $wrapContent
     * @param null   $styles
     *
     * @return string The converted string.
     */
    public function fromHTML($html, $wrapContent = true, $styles = null) {
        $this->_openXml = $html;

        $this->_setStyles($styles);
        $this->_preProcessLtGt();

        $this->_openXml = $this->_cleaner->cleanUpHTML($this->_openXml);
        if ($wrapContent) {
            $this->_getOpenXML();
        }

        $this->_processListStyle();
        $this->_processBreaks();
        $this->_openXml = $this->_processProperties->processPropertiesStyle(
                $this->_openXml, 0
        );
        $this->_removeStartSpaces();
        $this->_removeEndSpaces();

        $this->_processSpaces();

        $this->_postProcessLtGt();

        return $this->_openXml;
    }

    /**
     * @param array $styles Takes an array with `run` and `paragraph` as
     *                      options, than can contain the XML that goes inside
     *                      of the respective pPr and rPr tags
     */
    private function _setStyles($styles) {
        $this->_rStyle = array_key_exists('run', $styles) ? $styles['run'] : null;

        $this->_pStyle = array_key_exists('paragraph', $styles)
                ? $styles['paragraph'] : null;

    }

    /**
     * Remove empty blocks of XML from the end of the final output
     * NOTE This might be borked after adding styles
     */
    private function _removeEndSpaces() {
        $regex = sprintf(
                '/<w:t>%s%s$/', $this->_regexCloseP(), $this->_openP()
        );
        if (preg_match($regex, $this->_openXml)) {
            $this->_openXml = preg_replace($regex, '<w:t>', $this->_openXml);

            $this->_removeEndSpaces();
        }

    }

    /**
     * Remove empty blocks of XML from the start of the final output
     */
    private function _removeStartSpaces() {
        $regex = sprintf('/^%s%s/', $this->_openP(), $this->_regexCloseP());
        if (preg_match($regex, $this->_openXml)) {
            $this->_openXml = preg_replace($regex, '', $this->_openXml);

            $this->_removeStartSpaces();
        }

    }

    private function _getOpenXML() {
        $this->_openXml = $this->_openP() . $this->_openXml . $this->_closeP();
    }

    /**
     * First we check if there are multiple levels of lists. Only tested with 2
     * levels, not sure if this will work with more than 2 lists
     */
    private function _preProcessNestedLists() {
        /*
        '/<li>(.*?(?!<\/li>).*?)<(ul|ol).*?>(.*?)<\/\2.*?><\/li>/im',
        // This allows for html in top level nest item, but breaks plain text

        '/<li>([^<]*)<(?:ul|ol).*?>(.*?)<\/\2.*?><\/li>/im',
        // doesnt allow html before nest, but works with normal copy
         */

        $this->_listLevel = 1;

        $this->_openXml = preg_replace_callback(
                '/<li>
                (?>
                    [^<]+
                    |(<[uo]l)>
                    |<(?!\/?li)[^>]*>
                    |(?R)
                )*
                <\/li>
                (?(1)|(*F))
            /imx', [$this, 'preProcessNestedList'], $this->_openXml
        );

        $this->_listLevel = 0;
    }

    public function preProcessNestedList($html) {
        preg_match('/^<li>(.*?)<(ol|ul)>(.*?)<\/\2><\/li>$/', $html[0], $match);

        $output = '';
        if ($match[1]) {
            $output = sprintf('<li>%s</li>', $match[1]);
        }
        $output .= $this->processList($match[3]);

        return $output;
    }

    private function _processListStyle() {

        $this->_openXml = preg_replace("/\n/", ' ', $this->_openXml);

        $this->_preProcessNestedLists();

        $this->_openXml = preg_replace_callback(
                '/<(ul|ol).*?>(.*?)<\/\1>/im', [$this, 'processList'],
                $this->_openXml
        );

    }

    public function processList($html) {
        $html = is_array($html) ? $html[2] : $html;

        $output = '';

        $output .= preg_replace_callback(
                '/<li.*?>(.*?)<\/li>/im', [$this, 'processListItem'], $html
        );

        //        $output .= $this->_closeAndOpenP(true);

        if ($this->_listLevel === 0) {

            // Add a blank line after the list, otherwise it's attached
            $output .= $this->_closeAndOpenP(true);
        }

        $this->_listIndex += 1;

        return $output;
    }

    public function processListItem($html) {

        $html = sprintf(
                '</w:t></w:r></w:p><w:p><w:pPr>%5$s<w:pStyle w:val=\'ListParagraph\'/><w:numPr><w:ilvl w:val=\'%1$d\'/><w:numId w:val=\'%2$d\'/></w:numPr>%3$s</w:pPr><w:r>%3$s<w:t xml:space=\'preserve\'>%4$s',
                $this->_listLevel, $this->_listIndex, $this->_getStyle('r'),
                trim($html[1]), $this->_getStyle('p', false)
        );

        return $html;
    }

    private function _processBreaks() {
        $this->_openXml = preg_replace(
                "/(<\/p>)/mi", $this->_closeAndOpenP(true), $this->_openXml
        );
        $this->_openXml = preg_replace(
                "/(<br\s?\/?>)/mi", $this->_closeAndOpenP(true), $this->_openXml
        );

    }

    /**
     * @param $html
     *
     * @author © Alex Moore
     *
     * @return null|string|string[]
     */
    public function minifyHtml($html) {
        $re
                = '%# Collapse whitespace everywhere but in blacklisted elements.
        (?>             # Match all whitespans other than single space.
          [^\S ]\s*     # Either one [\t\r\n\f\v] and zero or more ws,
        | \s{2,}        # or two or more consecutive-any-whitespace.
        ) # Note: The remaining regex consumes no text at all...
        (?=             # Ensure we are not in a blacklist tag.
          [^<]*+        # Either zero or more non-"<" {normal*}
          (?:           # Begin {(special normal*)*} construct
            <           # or a < starting a non-blacklist tag.
            (?!/?(?:textarea|pre|script)\b)
            [^<]*+      # more non-"<" {normal*}
          )*+           # Finish "unrolling-the-loop"
          (?:           # Begin alternation group.
            <           # Either a blacklist start tag.
            (?>textarea|pre|script)\b
          | \z          # or end of file.
          )             # End alternation group.
        )  # If we made it here, we are not in a blacklist tag.
        %Six';

        return preg_replace($re, "", $html);
    }

    private function _processSpaces() {
        $this->_openXml = preg_replace("/(&nbsp;)/mi", " ", $this->_openXml);
        $this->_openXml = preg_replace(
                "/(<w:t>)/mi", "<w:t xml:space='preserve'>", $this->_openXml
        );

        $this->_openXml = $this->minifyHtml($this->_openXml);

    }

    /**
     * &lt; and &gt; need to be processed seprately because otherwise they're
     * parsed as < and >, which will break the XML
     */
    private function _preProcessLtGt() {
        $this->_openXml = preg_replace(
                '/\&(lt|gt);/im', '\$\$$1;', $this->_openXml
        );
        // Just in case also check for &amp;lt;
        $this->_openXml = preg_replace(
                '/\&amp;(lt|gt);/im', '\$\$$1;', $this->_openXml
        );
        //        prd(($this->_openXml));
    }

    /**
     * Reset the values again
     */
    private function _postProcessLtGt() {
        $this->_openXml = preg_replace(
                '/\$\$(lt|gt);/', '&$1;', $this->_openXml
        );

    }

    private function _closeAndOpenP($addStyling = false) {
        return $this->_closeP() . $this->_openP($addStyling);
    }

    private function _openP($addStyling = false) {
        $xml = '<w:p>%s<w:r>%s<w:t>';
        $pStyle = '';
        $rStyle = '';

        if ($addStyling && $this->_rStyle) {
            $rStyle = $this->_getStyle('r');
        }
        if ($addStyling && $this->_pStyle) {
            $pStyle = $this->_getStyle('p');
        }

        return sprintf($xml, $pStyle, $rStyle);
    }

    private function _closeP() {
        return '</w:t></w:r></w:p>';
    }

    private function _regexCloseP() {
        return str_replace('/', '\/', $this->_closeP());
    }

    /**
     * Returns XML style data
     *
     * @param string $type
     * @param bool $wrap
     *
     * @return null|string
     */
    private function _getStyle($type, $wrap = true) {
        $field = sprintf('_%sStyle', $type);
        if ( ! $wrap) {
            return $this->{$field};
        }

        return sprintf('<w:%1$sPr>%2$s</w:%1$sPr>', $type, $this->{$field});
    }

}