<?php
declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'no_multiple_statements_per_line' => true])
    ->setUsingCache(false)
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/bin', __DIR__.'/tests'])->append([__DIR__.'/bootstrap.php', __DIR__.'/public/index.php', __DIR__.'/public/router.php']));
