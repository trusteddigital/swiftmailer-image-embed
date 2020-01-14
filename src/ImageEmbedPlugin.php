<?php

namespace Hexanet\Swiftmailer;

use Swift_Events_SendEvent;
use Swift_Events_SendListener;
use Swift_Image;
use Swift_Mime_SimpleMimeEntity;
use Swift_Mime_SimpleMessage;

class ImageEmbedPlugin implements Swift_Events_SendListener
{
    /**
     * @var string
     */
    private $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath;
    }

    /**
     * @param Swift_Events_SendEvent $event
     */
    public function beforeSendPerformed(Swift_Events_SendEvent $event)
    {
        $message = $event->getMessage();

        if ($message->getContentType() === 'text/html') {
            $message->setBody($this->embedImages($message));
        }

        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), 'text/html') === 0) {
                $part->setBody($this->embedImages($message, $part), 'text/html');
            }
        }
    }

    /**
     * @param Swift_Events_SendEvent $event
     */
    public function sendPerformed(Swift_Events_SendEvent $event)
    {

    }

    /**
     * @param Swift_Mime_SimpleMessage         $message
     * @param Swift_Mime_SimpleMimeEntity|null $part
     *
     * @return string
     */
    protected function embedImages(Swift_Mime_SimpleMessage $message, Swift_Mime_SimpleMimeEntity $part = null)
    {
        $body = $part === null ? $message->getBody() : $part->getBody();

		$internalErrors = libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML($body);
		
		libxml_use_internal_errors($internalErrors);

        $images = $dom->getElementsByTagName('img');
        foreach ($images as $image) {
            $src = $image->getAttribute('src');

            /**
             * Prevent beforeSendPerformed called twice
             * see https://github.com/swiftmailer/swiftmailer/issues/139
             */
            if (strpos($src, 'cid:') === false) {
                $path = $this->getPathFromSrc($src);

                if ($this->fileExists($path)) {
                    $entity = \Swift_Image::fromPath($path);
                    $message->setChildren(
                        array_merge($message->getChildren(), [$entity])
                    );

                    $image->setAttribute('src', 'cid:' . $entity->getId());
                }
            }
        }
		
		$tables = $dom->getElementsByTagName('table');
		
		$findreplacelist = array();
		
		foreach($tables as $table)
		{
			foreach ($table->childNodes as $tr) {
			  if ($tr->nodeName == 'tr') {
			  
			foreach ($tr->childNodes as $td) {
			  
			  if ($td->nodeName == 'td') {

	            $src = $td->getAttribute('background');

	            if ($src && strpos($src, 'cid:') === false) {
					$path = $this->getPathFromSrc($src);

	                if ($this->fileExists($path)) {
        				$entity = \Swift_Image::fromPath($path);
	                    $message->setChildren(
	                        array_merge($message->getChildren(), [$entity])
	                    );

	                    $td->setAttribute('background', 'cid:' . $entity->getId());
	                    $td->setAttribute('style', str_replace($src,'cid:' . $entity->getId(),$td->getAttribute('style')));

						$fr = array("find"=>$src,"replace"=>'cid:' . $entity->getId());
						$findreplacelist[] = $fr;
					
	                }
	            }
				}
				}
			
			  }
			}	
		}			
		
		$html = $dom->saveHTML();
		
		if(count($findreplacelist))
		{
			foreach($findreplacelist as $fr)
			{
				$html = str_replace($fr['find'],$fr['replace'],$html);
			}
		}
	
        return $html;
    }

    protected function isUrl(string $path) : bool
    {
        return filter_var(preg_replace('/ /', '%20', $path), FILTER_VALIDATE_URL) !== false;
    }

    protected function getPathFromSrc(string $src) : string
    {
        if ($this->isUrl($src)) {
            return $src;
        }

        return $this->basePath . $src;
    }

    protected function fileExists(string $path) : bool
    {
        if ($this->isUrl($path)) {
            return !!@getimagesize($path);
        }

        return file_exists($path);
    }
}
