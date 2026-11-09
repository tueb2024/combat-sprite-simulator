# Shortcuts for bin/jumphp (everything runs in Docker). GAME=pong by default: make run GAME=mygame
GAME ?= pong
.PHONY: help setup engine install run test shot build serve relay lint test-engine
help:        ; @bin/jumphp help
setup:       ; bin/jumphp setup
engine:      ; bin/jumphp engine:build
install:     ; bin/jumphp install $(GAME)
run:         ; bin/jumphp run $(GAME)
test:        ; bin/jumphp test $(GAME)
test-engine: ; bin/jumphp test engine
shot:        ; bin/jumphp shot $(GAME)
build:       ; bin/jumphp build $(GAME)
serve:       ; bin/jumphp serve $(GAME)
relay:       ; bin/jumphp relay up
lint:        ; bin/jumphp lint
