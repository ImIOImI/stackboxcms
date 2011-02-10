<?php
namespace Module\Page;
use Alloy;

/**
 * Page Template Parser
 * 
 * Parses page templates for tokens to replace with content
 *
 * @package Stackbox CMS
 * @link http://stackboxcms.com/
 */
class Template extends Alloy\View\Template
{
    // Extension type (inherited from \Alloy\View\Template)
    protected $_default_format = 'html';
    protected $_default_extenstion = 'tpl';
    
    // Parsing storage
    protected $_content;
    protected $_tokens;
    
    // Special predefined types
    protected $_tokenTagType = 'tag';
    protected $_tags = array();
    protected $_tokenRegionType = 'region';
    protected $_regions = array();
    protected $_regionsType = array();
    
    
    /**
     * Parse template contents
     * 
     * @param $content string
     */
    public function parse()
    {
        if($this->_tokens) {
            return $this->_tokens;
        }
        
        // Get template content
        $content = $this->content();
        
        // Match all template tags
        $matches = array();
        preg_match_all("@<([\w]+):([^\s]*)([^>]*)>(.*)<\/\\1:\\2>@", $content, $matches, PREG_SET_ORDER);
        
        // Assemble list of tags by type
        $tokens = array();
        $ri = 0;
        foreach ($matches as $val) {
            $tagFull = $val[0];
            $tagNamespace = $val[1];
            $tagType = $val[2];
            $tagAttributes = trim($val[3]);
            $tagContent = $val[4];
            $matchedAttributes = array();
            if(!empty($tagAttributes)) {
                // Match all attributes (key="value")
                preg_match_all("/([^\s=]*)=\"([^\"]*)\"/", $tagAttributes, $matchedAttributes, PREG_SET_ORDER);
                // Assemble tag attributes
                $tagAttributes = array();
                foreach($matchedAttributes as $attr) {
                    $tagAttributes[$attr[1]] = $attr[2];
                }
            } else {
                $tagAttributes = array();
            }
            
            // Ouput array
            $token = array(
                'tag' => $tagFull,
                'type' => $tagType,
                'namespace' => $tagNamespace,
                'attributes' => $tagAttributes,
                'content' => $tagContent
                );
            
            // Store found tags
            if($tagType == $this->_tokenTagType) {
                // Set key as name or number
                if(isset($tagAttributes['name'])) {
                    $tokenName = $tagAttributes['name'];
                } else {
                    $tokenName = $ti++;
                }
                $this->_tags[$tokenName] = $token;
                
            // Store found regions
            } elseif($tagType == $this->_tokenRegionType) {
                // Set key as name or number
                if(isset($tagAttributes['name'])) {
                    $tokenName = $tagAttributes['name'];
                } else {
                    $tokenName = $ti++;
                }
                $regionType = isset($tagAttributes['type']) ? $tagAttributes['type'] : 'page';
                $this->_regions[$tokenName] = $token;
                $this->_regionsType[$regionType][] = $tokenName;
            }
            
            $tokens[] = $token;
        }
        
        $this->_tokens = $tokens;
        return $tokens;
    }
    
    
    /**
     * Get all found tokens, regardless of type
     * 
     * @return array
     */
    public function tokens()
    {
        // Parse template if is has not been parsed already
        if(!$this->_tokens) {
            $this->parse();
        }
        
        return $this->_tokens;
    }
    
    
    /**
     * Get all found tags
     * 
     * @return array
     */
    public function tags()
    {
        // Parse template if is has not been parsed already
        if(!$this->_tags) {
            $this->parse();
        }
        
        return $this->_tags;
    }
    
    
    /**
     * Get all found regions
     * 
     * @return array
     */
    public function regions()
    {
        // Parse template if is has not been parsed already
        if(!$this->_regions) {
            $this->parse();
        }
        
        return $this->_regions;
    }
    
    
    /**
     * Get all found global regions
     * 
     * @return array
     */
    public function regionsType($type)
    {
        // Parse template if is has not been parsed already
        if(!$this->_regionsType) {
            $this->parse();
        }
        
        return isset($this->_regionsType[$type]) ? $this->_regionsType[$type] : array();
    }
    
    
    /**
     * Remove lingering tags that have not been replaced with other content
     * Leaves any default text in place (text between <cx:tag></cx:tag>)
     */
    public function clean()
    {
        $cleanContent = $this->content();
        foreach($this->tokens() as $tag) {
            $cleanContent = str_replace($tag['tag'], $tag['content'], $cleanContent);
        }
        return $this->_content = $cleanContent;
    }
    
    
    /**
     * Replace given tag with content
     *
     * @param $tag string Full token string to replace
     * @param $replacement string
     * 
     * @return boolean
     */
    public function replaceToken($tag, $replacement)
    {
        $this->_content = str_replace($tag, $replacement, $this->content());
        return true;
    }
    
    
    /**
     * Replace template tag with content
     *
     * @param $tagName string Name of template tag to replace
     * @param $replacement string
     * 
     * @return boolean
     */
    public function replaceTag($tagName, $replacement)
    {
        $tags = $this->tags();
        if(isset($tags[$tagName])) {
            $this->_content = str_replace($tags[$tagName]['tag'], $replacement, $this->content());
            return true;
        } else {
            return false;
        }
    }
    
    
    /**
     * Replace template region with content
     *
     * @param $regionName string Name of template region to replace
     * @param $replacement string
     * 
     * @return boolean
     */
    public function replaceRegion($regionName, $replacement)
    {
        $regions = $this->regions();
        if(isset($regions[$regionName])) {
            $this->_content = str_replace($regions[$regionName]['tag'], $replacement, $this->content());
            return true;
        } else {
            return false;
        }
    }
    
    
    /**
     * Returns full template filename with format and extension
     *
     * @param OPTIONAL $template string (Name of the template to return full file format)
     * @return string
     */
    public function templateFilename($template = null)
    {
        if(null === $template) {
            $template = $this->template();
        }
        return $template . '.' . $this->_default_extenstion . '.' . $this->format();
    }
    
    
    /**
     * Set template contents
     * 
     * @param string $content
     * @return string
     */
    public function content($parsePHP = true)
    {
        if(null === $this->_content) {
            $this->_content = parent::content(false);
        }
        return $this->_content;
    }
    
    
    /**
     * Converts view object to string on the fly
     *
     * @return string
     */
    public function __toString()
    {
        $this->clean();
        return parent::__toString();
    }
}