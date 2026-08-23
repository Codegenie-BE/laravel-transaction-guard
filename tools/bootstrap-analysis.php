<?php

declare(strict_types=1);

$root = dirname(__DIR__);

foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/RuleCatalog.php',
    'src/Analysis/Finding.php',
    'src/Analysis/AnalysisConfig.php',
    'src/Analysis/ClassMetadata.php',
    'src/Analysis/OperationCatalog.php',
    'src/Analysis/RedisOperationClassifier.php',
    'src/Analysis/RedisFindingRefiner.php',
    'src/Analysis/DatabaseDriverPolicy.php',
    'src/Analysis/StaticExpressionResolver.php',
    'src/Analysis/MetadataAttributeResolver.php',
    'src/Analysis/ModelRelationExtractor.php',
    'src/Analysis/FileContext.php',
    'src/Analysis/FileContextMap.php',
    'src/Analysis/ClassMetadataIndex.php',
    'src/Analysis/SourceIndex.php',
    'src/Analysis/SourceScanner.php',
    'src/Analysis/AnalysisResult.php',
    'src/Analysis/Baseline.php',
    'src/TransactionGuard.php',
] as $file) {
    require_once $root.'/'.$file;
}
