.PHONY: lint
lint:
	find . -name "*.php" -exec php -l {} \;
