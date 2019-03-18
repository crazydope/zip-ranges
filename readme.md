# zip-ranges
Утилита обрабатывает range-запросы
и отдает zip-архив частями

## Установка
```
composer require crazydope/zip-ranges
```
## Как использовать?
```
$download = new \crazydope\http\ZipFile($pathToFile,[$displayName],[$delay]);
$download->process();
```