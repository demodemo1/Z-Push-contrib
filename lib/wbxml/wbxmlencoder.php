<?php
/***********************************************
* File      :   wbxmlencoder.php
* Project   :   Z-Push
* Descr     :   WBXMLEncoder encodes to Wap Binary XML
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/


class WBXMLEncoder extends WBXMLDefs {
    private $_dtd;
    private $_out;
    private $_outLog;

    private $_tagcp = 0;

    private $log = false;
    private $logStack = array();

    // We use a delayed output mechanism in which we only output a tag when it actually has something
    // in it. This can cause entire XML trees to disappear if they don't have output data in them; Ie
    // calling 'startTag' 10 times, and then 'endTag' will cause 0 bytes of output apart from the header.

    // Only when content() is called do we output the current stack of tags

    private $_stack;

    private $multipart; // the content is multipart
    private $bodyparts;

    public function WBXMLEncoder($output, $multipart = false) {
        $this->log = defined('WBXML_DEBUG') && WBXML_DEBUG;

        $this->_out = $output;
        $this->_outLog = StringStreamWrapper::Open("");

        // reverse-map the DTD
        foreach($this->dtd["namespaces"] as $nsid => $nsname) {
            $this->_dtd["namespaces"][$nsname] = $nsid;
        }

        foreach($this->dtd["codes"] as $cp => $value) {
            $this->_dtd["codes"][$cp] = array();
            foreach($this->dtd["codes"][$cp] as $tagid => $tagname) {
                $this->_dtd["codes"][$cp][$tagname] = $tagid;
            }
        }
        $this->_stack = array();
        $this->multipart = $multipart;
        $this->bodyparts = array();
    }

    /**
     * Puts the WBXML header on the stream
     *
     * @access public
     * @return
     */
    public function startWBXML() {
        if ($this->multipart) {
            header("Content-Type: application/vnd.ms-sync.multipart");
            ZLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->startWBXML() type: vnd.ms-sync.multipart");
        }
        else {
            header("Content-Type: application/vnd.ms-sync.wbxml");
            ZLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->startWBXML() type: vnd.ms-sync.wbxml");
        }

        $this->outByte(0x03); // WBXML 1.3
        $this->outMBUInt(0x01); // Public ID 1
        $this->outMBUInt(106); // UTF-8
        $this->outMBUInt(0x00); // string table length (0)
    }

    /**
     * Puts a StartTag on the output stack
     *
     * @param $tag
     * @param $attributes
     * @param $nocontent
     *
     * @access public
     * @return
     */
    public function startTag($tag, $attributes = false, $nocontent = false) {
        $stackelem = array();

        if(!$nocontent) {
            $stackelem['tag'] = $tag;
            $stackelem['nocontent'] = $nocontent;
            $stackelem['sent'] = false;

            array_push($this->_stack, $stackelem);

            // If 'nocontent' is specified, then apparently the user wants to force
            // output of an empty tag, and we therefore output the stack here
        } else {
            $this->_outputStack();
            $this->_startTag($tag, $nocontent);
        }
    }

    /**
     * Puts an EndTag on the stack
     *
     * @access public
     * @return
     */
    public function endTag() {
        $stackelem = array_pop($this->_stack);

        // Only output end tags for items that have had a start tag sent
        if($stackelem['sent']) {
            $this->_endTag();

            if(count($this->_stack) == 0)
                ZLog::Write(LOGLEVEL_DEBUG, "WBXMLEncoder->endTag() WBXML output completed");

            if(count($this->_stack) == 0 && $this->multipart == true) {
                $this->processMultipart();
            }
            if(count($this->_stack) == 0)
                $this->writeLog();
        }
    }

    /**
     * Puts content on the output stack
     *
     * @param $content
     *
     * @access public
     * @return string
     */
    public function content($content) {
        // We need to filter out any \0 chars because it's the string terminator in WBXML. We currently
        // cannot send \0 characters within the XML content anywhere.
        $content = str_replace("\0","",$content);

        if("x" . $content == "x")
            return;
        $this->_outputStack();
        $this->_content($content);
    }

    /**
     * Gets the value of multipart
     *
     * @access public
     * @return boolean
     */
    public function getMultipart() {
        return $this->multipart;
    }

    /**
     * Adds a bodypart
     *
     * @param Stream $bp
     *
     * @access public
     * @return void
     */
    public function addBodypartStream($bp) {
        if (!is_resource($bp))
            throw new Exception("WBXMLEncoder->addBodypartStream(): trying to add a ".gettype($bp)." instead off a stream");
        if ($this->multipart)
            $this->bodyparts[] = $bp;
    }

    /**
     * Gets the number of bodyparts
     *
     * @access public
     * @return int
     */
    public function getBodypartsCount() {
        return count($this->bodyparts);
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private WBXMLEncoder stuff
     */

    /**
     * Output any tags on the stack that haven't been output yet
     *
     * @access private
     * @return
     */
    private function _outputStack() {
        for($i=0;$i<count($this->_stack);$i++) {
            if(!$this->_stack[$i]['sent']) {
                $this->_startTag($this->_stack[$i]['tag'], $this->_stack[$i]['nocontent']);
                $this->_stack[$i]['sent'] = true;
            }
        }
    }

    /**
     * Outputs an actual start tag
     *
     * @access private
     * @return
     */
    private function _startTag($tag, $nocontent = false) {
        if ($this->log)
            $this->logStartTag($tag, $nocontent);

        $mapping = $this->getMapping($tag);

        if(!$mapping)
            return false;

        if($this->_tagcp != $mapping["cp"]) {
            $this->outSwitchPage($mapping["cp"]);
            $this->_tagcp = $mapping["cp"];
        }

        $code = $mapping["code"];

        if(!isset($nocontent) || !$nocontent)
            $code |= 0x40;

        $this->outByte($code);
    }

    /**
     * Outputs actual data
     *
     * @access private
     * @return
     */
    private function _content($content) {
        if ($this->log)
            $this->logContent($content);
        $this->outByte(self::WBXML_STR_I);
        $this->outTermStr($content);
    }

    /**
     * Outputs an actual end tag
     *
     * @access private
     * @return
     */
    private function _endTag() {
        if ($this->log)
            $this->logEndTag();
        $this->outByte(self::WBXML_END);
    }

    /**
     * Outputs a byte
     *
     * @param $byte
     *
     * @access private
     * @return
     */
    private function outByte($byte) {
        fwrite($this->_out, chr($byte));
        fwrite($this->_outLog, chr($byte));
    }

    /**
     * Outputs a string table
     *
     * @param $uint
     *
     * @access private
     * @return
     */
    private function outMBUInt($uint) {
        while(1) {
            $byte = $uint & 0x7f;
            $uint = $uint >> 7;
            if($uint == 0) {
                $this->outByte($byte);
                break;
            } else {
                $this->outByte($byte | 0x80);
            }
        }
    }

    /**
     * Outputs content with string terminator
     *
     * @param $content
     *
     * @access private
     * @return
     */
    private function outTermStr($content) {
        fwrite($this->_out, $content);
        fwrite($this->_out, chr(0));
        fwrite($this->_outLog, $content);
        fwrite($this->_outLog, chr(0));
    }

    /**
     * Switches the codepage
     *
     * @param $page
     *
     * @access private
     * @return
     */
    private function outSwitchPage($page) {
        $this->outByte(self::WBXML_SWITCH_PAGE);
        $this->outByte($page);
    }

    /**
     * Get the mapping for a tag
     *
     * @param $tag
     *
     * @access private
     * @return array
     */
    private function getMapping($tag) {
        $mapping = array();

        $split = $this->splitTag($tag);

        if(isset($split["ns"])) {
            $cp = $this->_dtd["namespaces"][$split["ns"]];
        }
        else {
            $cp = 0;
        }

        $code = $this->_dtd["codes"][$cp][$split["tag"]];

        $mapping["cp"] = $cp;
        $mapping["code"] = $code;

        return $mapping;
    }

    /**
     * Split a tag from a the fulltag (namespace + tag)
     *
     * @param $fulltag
     *
     * @access private
     * @return array        keys: 'ns' (namespace), 'tag' (tag)
     */
    private function splitTag($fulltag) {
        $ns = false;
        $pos = strpos($fulltag, chr(58)); // chr(58) == ':'

        if($pos) {
            $ns = substr($fulltag, 0, $pos);
            $tag = substr($fulltag, $pos+1);
        }
        else {
            $tag = $fulltag;
        }

        $ret = array();
        if($ns)
            $ret["ns"] = $ns;
        $ret["tag"] = $tag;

        return $ret;
    }

    /**
     * Logs a StartTag to ZLog
     *
     * @param $tag
     * @param $nocontent
     *
     * @access private
     * @return
     */
    private function logStartTag($tag, $nocontent) {
        $spaces = str_repeat(" ", count($this->logStack));
        if($nocontent)
            ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . " <$tag/>");
        else {
            array_push($this->logStack, $tag);
            ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . " <$tag>");
        }
    }

    /**
     * Logs a EndTag to ZLog
     *
     * @access private
     * @return
     */
    private function logEndTag() {
        $spaces = str_repeat(" ", count($this->logStack));
        $tag = array_pop($this->logStack);
        ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . "</$tag>");
    }

    /**
     * Logs content to ZLog
     *
     * @param $content
     *
     * @access private
     * @return
     */
    private function logContent($content) {
        $spaces = str_repeat(" ", count($this->logStack));
        ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . $content);
    }

    /**
     * Processes the multipart response
     *
     * @access private
     * @return void
     */
    private function processMultipart() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("WBXMLEncoder->processMultipart() with %d parts to be processed", $this->getBodypartsCount()));
        $len = ob_get_length();
        // #190 - KD 2015-06-08 Replace ob_get_flush with ob_get_clean; because we don't want to disable the buffering
        $buffer = ob_get_clean();
        $nrBodyparts = $this->getBodypartsCount();
        $blockstart = (($nrBodyparts + 1) * 2) * 4 + 4;

        $data = pack("iii", ($nrBodyparts + 1), $blockstart, $len);

        foreach ($this->bodyparts as $bp) {
            $blockstart = $blockstart + $len;
            if (is_resource($bp)) {
                $len = fstat($bp);
                $len = (isset($len['size'])) ? $len['size'] : 0;
            } elseif (is_string($bp)) {
                $len = strlen($bp);
            } else {
                throw new Exception("bp is a ".gettype($bp)."!?!");
            }
            $data .= pack("ii", $blockstart, $len);
        }

        fwrite($this->_out, $data);
        fwrite($this->_out, $buffer);
        fwrite($this->_outLog, $data);
        fwrite($this->_outLog, $buffer);
        foreach($this->bodyparts as $bp) {
            if (is_resource($bp)) {
                stream_copy_to_stream($bp, $this->_out);
                stream_copy_to_stream($bp, $this->_outLog);
                fclose($bp);
            } elseif (is_string($bp)) {
                fwrite($this->_out, $bp);
		fwrite($this->_outLog, $bp);
            } else {
                throw new Exception("bp is a ".gettype($bp)."!?!");
            }
        }
    }

    /**
     * Writes the sent WBXML data to the log if it is not bigger than 512K.
     *
     * @access private
     * @return void
     */
    private function writeLog() {
        $stat = fstat($this->_outLog);
        if ($stat['size'] < 524288) {
            $data = base64_encode(stream_get_contents($this->_outLog, -1,0));
        }
        else {
            $data = "more than 512K of data";
        }
        ZLog::Write(LOGLEVEL_WBXML, "WBXML-OUT: ". $data, false);
    }
}
