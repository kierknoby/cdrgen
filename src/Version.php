<?php

namespace CdrGen;

final class Version
{
    public const VERSION = '1.1.0';
    public const BASE_REVISION = 'f3dcc9f0af7fcfb428f840004856d858d6de8a4c';

    /** Set by a packager/importer when an exact source revision is known. */
    public const SOURCE_REVISION = null;

    private function __construct()
    {
    }
}
