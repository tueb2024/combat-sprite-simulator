<?php

namespace JumPHP\Testing;

// The base of the tests that play a project, under a name that fits every kind of project: GameTestCase is the same class
// (AppTestCase is the one of desktop apps, with no window).
class_alias(GameTestCase::class, 'JumPHP\\Testing\\ProjectTestCase');
