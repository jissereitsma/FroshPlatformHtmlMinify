<?php declare(strict_types=1);

namespace Frosh\HtmlMinify\Listener;

use Composer\Autoload\ClassLoader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use JSMin\JSMin;

class ResponseListener
{
    private $javascriptPlaceholder = '##SCRIPTPOSITION##';
    private $spacePlaceholder = '##SPACE##';

    /**
     * @param ResponseEvent $event
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $response = $event->getResponse();

        if ($response instanceof BinaryFileResponse ||
            $response instanceof StreamedResponse) {
            return;
        }

        if (strpos($response->headers->get('Content-Type', ''), 'text/html') === false) {
            return;
        }

        $file = __DIR__.'/../../vendor/autoload.php';
        $classLoader = require_once $file;

        if ($classLoader instanceof ClassLoader) {
            $classLoader->unregister();
            $classLoader->register(false);
        }

        $this->minify($response);
    }

    private function minify(Response $response): void
    {
        $startTime = microtime(true);
        $content = $response->getContent();
        $lengthInitialContent = mb_strlen($content, 'utf8');

        $this->minifySourceTypes($content);

        $javascripts = $this->extractCombinedInlineScripts($content);

        $this->minifyHtml($content);

        //add the minified javascript after minifying html
        $content = str_replace($this->javascriptPlaceholder, '<script>' . $javascripts . '</script>', $content);

        $this->assignCompressionHeader($response, $content, $lengthInitialContent, $startTime);

        $response->setContent($content);
    }

    private function minifyJavascript(string $content): string {
        $jsMin = new JSMin($content);
        return $jsMin->min();
    }

    private function minifyHtml(string &$content): void {
        $search = [
            '/(\n|^)(\x20+|\t)/',
            '/(\n|^)\/\/(.*?)(\n|$)/',
            '/\n/',
            '/\<\!--.*?-->/',
            '/(\x20+|\t)/', # Delete multispace (Without \n)
            '/span\>\s+/', # keep whitespace after span tags
            '/\s+\<span/', # keep whitespace before span tags
            '/button\>\s+/', # keep whitespace after span tags
            '/\s+\<button/', # keep whitespace before span tags
            '/\>\s+\</', # strip whitespaces between tags
            '/(\"|\')\s+\>/', # strip whitespaces between quotation ("') and end tags
            '/=\s+(\"|\')/', # strip whitespaces between = "'
            '/' . $this->spacePlaceholder . '/', # replace the spacePlaceholder at the end
        ];

        $replace = [
            "\n",
            "\n",
            ' ',
            '',
            ' ',
            'span>' . $this->spacePlaceholder,
            $this->spacePlaceholder . '<span',
            'button>' . $this->spacePlaceholder,
            $this->spacePlaceholder . '<button',
            '><',
            '$1>',
            '=$1',
            ' ',
        ];

        $content = trim(preg_replace($search, $replace, $content));
    }

    private function extractCombinedInlineScripts(string &$content): string
    {
        $scriptContents = '';
        $index = 0;
        $placeholder = $this->javascriptPlaceholder;
        if (strpos($content, '</script>') !== false) {
            $content = preg_replace_callback('#<script>(.*?)<\/script>#s', function ($matches) use (&$scriptContents, &$index, $placeholder) {
                $index++;
                $content = trim($matches[1]);

                if (!$this->str_ends_with($content, ';')) {
                    $content .= ';';
                }

                $scriptContents .= $content . PHP_EOL;
                return $index === 1 ? $placeholder : '';
            }, $content);
        }

        return $this->minifyJavascript($scriptContents);
    }

    private function minifySourceTypes(&$content): void
    {
        $search = [
            '/ type=["\']text\/javascript["\']/',
            '/ type=["\']text\/css["\']/',
        ];
        $replace = '';
        $content = preg_replace($search, $replace, $content);
    }

    private function assignCompressionHeader(Response $response, string $content, int $lengthInitialContent, float $startTime): void
    {
        $lengthContent = mb_strlen($content, 'utf8');
        $savedData = round(100 - 100 / ($lengthInitialContent / $lengthContent), 2);
        $timeTook = (int)((microtime(true) - $startTime) * 1000);

        $response->headers->add(['X-Html-Compressor' => time() . ': ' . $savedData . '% ' . $timeTook . 'ms']);
    }

    private function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }
}
