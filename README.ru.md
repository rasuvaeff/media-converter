# rasuvaeff/media-converter

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/media-converter/v)](https://packagist.org/packages/rasuvaeff/media-converter)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/media-converter/downloads)](https://packagist.org/packages/rasuvaeff/media-converter)
[![Build](https://github.com/rasuvaeff/media-converter/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/media-converter/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/media-converter/php)](https://packagist.org/packages/rasuvaeff/media-converter)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Универсальная типобезопасная PHP-обёртка над subprocess `ffmpeg` / `ffprobe`.
Обработка медиа собирается из небольших **операций** — transcode, trim, scale,
crop, extract audio, remux — в `Pipeline`, который материализует точную
команду ffmpeg. Никаких shell-строк: каждый путь — отдельный элемент argv.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный API-справочник, который можно передать модели.

## Требования

- PHP 8.3+
- бинарники `ffmpeg` и `ffprobe`, установленные и исполняемые (для запуска
  собранных команд; для сборки и инспекции pipeline бинарник не нужен)
- Runtime-зависимости: `symfony/process`, `rasuvaeff/retry`,
  `rasuvaeff/bulkhead`, `rasuvaeff/duration`
- Для **использования** пакета PHP-расширения не нужны. `ext-bcmath` /
  `ext-intl` указаны в `config.platform` только чтобы `composer install`
  проходил на хостах без них — они требуются dev-инструментом
  `roave/backward-compatibility-check`, а не runtime-кодом. Ставьте их только
  если разработчики пакета хотят запускать `composer bc-check`.
- `CachedProbesMedia` опционален и требует реализацию `psr/simple-cache` —
  пакет лишь *suggest*'ит `psr/simple-cache`, поэтому добавьте её (плюс
  конкретный кэш, например `symfony/cache`), когда используете этот декоратор.

## Установка

```bash
composer require rasuvaeff/media-converter
```

## Статус

Полный v1 набор на месте. Кратко:

| Область | Что входит |
|---|---|
| Композиция | `Pipeline` (неизменяемый), `CommandSpec`, `toArgv()` для сборки/инспекции без запуска |
| Операции трансформации | `Transcode`, `Remux`, `Trim`, `Scale`, `Crop`, `Rotate`, `Pad`, `Fps`, `ExtractAudio` |
| Кадры / превью | `Thumbnail`, `SpriteSheet`, `AnimatedPreview`, `TextOverlay` |
| Оверлеи / аудио | `Watermark`, `ReplaceAudio`, `NormalizeLoudness`, `BurnSubtitles`, `ExtractSubtitles`, `SelectStreams` |
| Метаданные | `AddMetadata`, `AddArtwork` |
| Упаковка | `PackageHls`, `PackageDash`, `Pipeline::concat()` |
| Пресеты | `Preset\Presets` — готовые pipeline (HLS/DASH → MP4, WebM, MP3/AAC, thumbnails, социальные клипы) |
| Движок | `MediaConverter` — исполнение через `symfony/process`, опциональные `retry` + `bulkhead`, wall-clock/idle таймауты, транзакционный staging, кооперативная отмена |
| Probing | `FfprobeMediaInfo`, `CachedProbesMedia` (PSR-16), отказ при DRM, отчёт о прогрессе |

Полный запускаемый пример с probing, фазами, прогрессом и typed-ошибками:
`php examples/convert.php input.mp4 out.mp4`.

## Использование

Pipeline начинается с источника и добавляет операции; каждая операция вносит
вклад в собираемую команду.

```php
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\MediaConverter\FfmpegBinary;
use Rasuvaeff\MediaConverter\Operation\{Crop, Scale, Transcode, Trim};
use Rasuvaeff\MediaConverter\Pipeline;

$binary = FfmpegBinary::default();

$pipeline = Pipeline::from('input.mov')
    ->add(new Trim(Duration::seconds(30), Duration::seconds(90)))
    ->add(new Scale(height: 720))
    ->add(new Crop(720, 720, 280, 0))
    ->add(new Transcode(videoCodec: 'libx264', audioCodec: 'aac', videoBitrateKbps: 2_500));

$argv = $pipeline->toArgv($binary, 'clip.mp4');
// ['/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-y', '-i', 'input.mov',
//  '-ss', '30', '-to', '90', '-vf', 'scale=-2:720,crop=720:720:280:0',
//  '-c:v', 'libx264', '-c:a', 'aac', '-b:v', '2500k', 'clip.mp4']
```

`Pipeline` неизменяем — `add()` возвращает новый pipeline.

### Запуск pipeline

`MediaConverter` исполняет pipeline. Ему нужен `RunsProcess` (шов subprocess;
пакет поставляет `SymfonyProcessRunner`) и опционально `Bulkhead`
(ограничить число одновременных ffmpeg на воркер) и `Retry` (ретрай
транзиентных upstream-ошибок — ненулевой exit со stderr, похожим на HTTP 5xx /
connection reset; детерминированные провалы не ретраятся):

```php
use Rasuvaeff\MediaConverter\MediaConverter;
use Rasuvaeff\MediaConverter\SymfonyProcessRunner;
use Rasuvaeff\Retry\Retry;

$converter = new MediaConverter(
    binary: FfmpegBinary::default(),
    runner: new SymfonyProcessRunner(),
    retry: Retry::exponential(maxAttempts: 3),   // опционально
);

$result = $converter->run($pipeline, 'clip.mp4'); // ConversionResult, либо бросает ConversionFailed
```

Retry оборачивает bulkhead, поэтому слот освобождается на время backoff.
`Rasuvaeff\Bulkhead\BulkheadFullException` пробрасывается как есть. Тесты
инжектят свой `RunsProcess`; `RunsProcess::run()` возвращает `ProcessOutcome`
(`exitCode`, `stderrTail`, `timedOut`).

ffmpeg пишет в приватный staging-каталог рядом с destination. При любом
провале, включая исчерпание retry или исключение callback, staging удаляется,
а существующий destination остаётся нетронутым. При успехе staged-файлы
коммитятся; поэтому безопасен и случай `source === outputPath`. Параллельные
конвертации одного destination сериализуются destination-lock.

### Прогресс и метаданные (`FfprobeMediaInfo`)

Передайте `ProbesMedia`, чтобы получить прогресс и предварительную DRM-проверку
— обе фичи опциональны, без prober'а — no-op:

```php
use Rasuvaeff\MediaConverter\FfprobeMediaInfo;
use Rasuvaeff\MediaConverter\Progress\ProgressEvent;

$converter = new MediaConverter(
    binary: FfmpegBinary::default(),
    runner: new SymfonyProcessRunner(),
    prober: new FfprobeMediaInfo(FfmpegBinary::default(), new SymfonyProcessRunner()),
);

$result = $converter->run($pipeline, 'clip.mp4', onProgress: function (ProgressEvent $event): void {
    if ($event->isDeterminate()) {
        printf("%.0f%%\n", $event->fraction() * 100);
    }
});
```

С prober'ом каждый `run()` один раз пробирует каждый media-вход до ffmpeg и
вне retry. Зашифрованный вход отклоняется с `ConversionFailureReason::Drm`;
вывод ffprobe с упоминанием decrypt даёт ту же причину даже при ненулевом exit
code. Все локальные входы учитываются в `inputBytes()`, но длительность
прогресса складывается только из timeline-входов (watermark не пробируется и
не входит в timeline). Trim и animated preview корректируют длительность
вывода, а одно-кадровые результаты и `ReplaceAudio(shortest: true)` остаются
indeterminate. Без prober'а — или когда длительность неизвестна — прогресс остаётся
indeterminate (`ProgressEvent::fraction()` равен null); сам `$onProgress` —
no-op, пока вы его не передали, и только тогда в argv добавляется
`-progress pipe:1 -nostats`. У progress-сэмплов есть `phase()` со значениями
`probing`, `running`, `committing`, `completed`. `ConversionResult::command()`
возвращает точный argv ffmpeg.

Для кооперативной отмены передайте `CancellationToken` в аргумент `$token`
метода `run()`. Движок проверяет токен между фазами и перед каждым чанком
вывода ffmpeg, бросая `ConversionCancelled` в момент, когда токен установлен, и
откатывая любой staged-вывод:

```php
use Rasuvaeff\MediaConverter\CancellationToken;

$token = new CancellationToken();
// отмена из обработчика сигнала, request-abort или по дедлайну:
$converter->run($pipeline, 'clip.mp4', token: $token);
```

Долгоживущие воркеры, пробирующие одни и те же файлы, могут обернуть любой
`ProbesMedia` в PSR-16-декоратор `CachedProbesMedia` (нужна реализация
`psr/simple-cache`, например `symfony/cache`):

```php
use Rasuvaeff\MediaConverter\CachedProbesMedia;

$prober = new CachedProbesMedia(
    inner: new FfprobeMediaInfo(FfmpegBinary::default(), new SymfonyProcessRunner()),
    cache: $psr16Cache,
    ttlSeconds: 86_400, // default; null = default backend'а
);
```

Ключ кэша — хэш `(path, filesize, filemtime)`: изменённый локальный файл
автоматически получает новый ключ, а TTL защищает только от переиспользования
inode. Для URL size/mtime недоступны — ключ вырождается в path-only, и
свежесть целиком определяется TTL. Мусорное значение в кэше трактуется как
miss, а не ошибка.

### Операции

Каждая операция реализует `Operation\OperationInterface`. Фильтр-операции
применяются в порядке добавления (они **не** коммутативны: `scale,crop` ≠
`crop,scale`).

| Операция | Эффект |
|---|---|
| `Transcode(?videoCodec, ?audioCodec, ?videoBitrateKbps, ?audioBitrateKbps)` | Перекодирование; имена кодеков — ffmpeg-энкодеры (`libx264`, `aac`, …) |
| `Remux()` | Stream-copy (`-c copy`) в новый контейнер, без потерь |
| `Trim(Duration $from, ?Duration $to, fastSeek = false)` | Оставить срез `[from, to)` (точный output-side seek по умолчанию; input-side fast-seek по запросу) |
| `Scale(?width, ?height)` | Ресайз; null-размер сохраняет пропорции (`-2`) |
| `Crop(width, height, x = 0, y = 0)` | Кроп прямоугольника |
| `Rotate(90\|180\|270)` | Поворот на прямой угол |
| `Pad(width, height, x = 0, y = 0, color = 'black')` | Паддинг до холста |
| `Fps(int)` | Постоянный frame rate |
| `ExtractAudio(codec = 'libmp3lame', ?bitrateKbps = 192)` | Убрать видео, кодировать аудио |
| `Thumbnail(Duration $at, ?width)` | Один статичный кадр (`-frames:v 1`, без аудио) |
| `SpriteSheet(rows, cols, Duration $interval, ?tileWidth)` | Контактный лист `cols x rows`, кадр каждые `$interval` |
| `AnimatedPreview(Duration $from, Duration $to, fps = 10, width = 320)` | Анимированный GIF-превью `[from, to)` с оптимизированной палитрой |
| `TextOverlay(text, Position $position = BottomRight, margin = 16, fontSize = 24, fontColor = 'white', ?fontFile)` | Вписать текст в видео (`drawtext`) |
| `Watermark(image, Position $position = BottomRight, margin = 16, ?opacity)` | Наложить изображение (второй вход) на видео |
| `ReplaceAudio(audioSource, shortest = true)` | Заменить аудио дорожкой из второго входа |
| `NormalizeLoudness(integratedLufs = -16, truePeakDbtp = -1.5, loudnessRangeLu = 11)` | Нормализация громкости EBU R128 (`loudnorm`) |
| `BurnSubtitles(srtOrAss)` | Вписать субтитры `.srt`/`.ass` в видео |
| `ExtractSubtitles(streamIndex = 0)` | Извлечь один поток субтитров (вывод pipeline — файл субтитров) |
| `SelectStreams(?videoIndex, ?audioIndex, ?subtitleIndex, optional = false)` | Выбрать конкретные streams основного входа |
| `PackageHls(segmentSeconds = 6, ?segmentFilenamePattern)` | Упаковать в HLS VOD плейлист + `.ts`-сегменты |
| `PackageDash(segmentSeconds = 5)` | Упаковать в DASH-манифест + `.m4s`-сегменты |
| `AddMetadata(array $tags)` | Записать метаданные контейнера (`-metadata key=value`) |
| `AddArtwork::forAudio(image, id3v2 = true)` / `AddArtwork::forVideo(image)` | Встроить обложку как `attached_pic`-поток |

Несовместимые комбинации отклоняются **до** запуска ffmpeg: `Remux()` вместе
с любым фильтром или кодек-операцией, или `AnimatedPreview`/`Watermark`
вместе с другой фильтр-операцией, бросает `ConversionFailed` с
`ConversionFailureReason::IncompatibleOperations`.

Терминальные операции явно объявляют тип результата. `ExtractAudio` нельзя
комбинировать с video-producing операцией вроде `Watermark`; thumbnail,
animated-preview и extract subtitles с несовместимыми операциями отклоняются
до ffmpeg, а не создают файл с неправильным набором потоков.

Палитровый граф `AnimatedPreview` (`split`/`palettegen`/`paletteuse`) имеет
один вход и один выход, поэтому рендерится через обычную filter-цепочку —
`-filter_complex` не нужен — но это цельный самодостаточный граф со своими
trim/fps/scale внутри, поэтому его нельзя комбинировать с `Scale`, `Crop`
или другой фильтр-операцией (отдельный `Trim` — можно, он не добавляет
фильтр).

`Watermark` требует второй вход (изображение оверлея), поэтому собирает
`-filter_complex`-граф вместо обычного `-vf` — ffmpeg не позволяет совмещать
`-vf` и `-filter_complex` для одного выходного потока, поэтому `Watermark`
тоже нельзя комбинировать с обычной video-фильтр-операцией (audio-фильтр,
например `NormalizeLoudness`, комбинируется нормально). В одном pipeline
поддерживается только один `Watermark`. Выходные потоки занимают семантические
video/audio slots, поэтому `Watermark` вместе с `ReplaceAudio` мапит видео с
watermark и заменённое аудио без устаревших `-map`. Явный
`SelectStreams(videoIndex: …)` нельзя комбинировать с `Watermark` или
`Pipeline::concat()` — видео-поток там выходит из фильтр-графа, а не из
выбираемого входа — и отклоняется при любом порядке `add()`.

Только одна операция может делать seek по выходному таймлайну: комбинация
`Trim` с `Thumbnail` (обе эмитят `-ss`) отклоняется — иначе поздний `-ss`
молча победил бы и дал кадр вне вырезанного диапазона. Отдельный `Trim` с
`AnimatedPreview` по-прежнему допустим — собственные `from`/`to` превью
полностью переопределяют trim, что безвредно (и избыточно).

По умолчанию `Trim` делает output-side seek (`-ss`/`-to` после `-i`): точный
до кадра, но ffmpeg декодирует всё до точки среза. С
`Trim(..., fastSeek: true)` `-ss` переносится до `-i`, и ffmpeg прыгает по
индексу контейнера — на длинном входе это секунды вместо минут. Конец среза
тогда эмитится как длительность (`-t to-from`), потому что input-side seek
сбрасывает выходные таймстемпы; итоговый срез `[from, to)` тот же. При
перекодировании современный ffmpeg всё равно декодирует от предыдущего
keyframe и отбрасывает лишнее, так что срез остаётся точным; с `Remux`
(stream copy) оба режима попадают на keyframe. Fast-seek отклоняется на
`Pipeline::concat()`-pipeline — input-опция применилась бы только к первому
сегменту.

`fontFile` у `TextOverlay` и путь субтитров у `BurnSubtitles` не могут
содержать одинарную кавычку — у ffmpeg нет способа вписать литеральную `'` в
аргумент фильтра, поэтому обе операции отклоняют такой путь в конструкторе,
а не собирают заведомо неверную команду.

`TextOverlay` без явного `fontFile` заставляет `drawtext` откатываться к
fontconfig-семейству `Sans`. Оно резолвится только если на хосте установлен
находимый шрифт — минимальный образ (например голый Alpine с ffmpeg) не несёт
ни одного, и ffmpeg падает в рантайме с *"Cannot find a valid font for the
family Sans"*. Для предсказуемого результата передайте реальный `fontFile`
(или установите шрифтовой пакет вроде `ttf-dejavu` и укажите на
`…/DejaVuSans.ttf`). См. `examples/text-overlay.php`.

`PackageHls` и `PackageDash` задают только формат вывода/сегментацию — они
комбинируются с `Remux` (упаковка без потерь) или `Transcode` (перекодирование
с сегментацией), как любая codec-only операция. Манифест и все sidecar-файлы
проходят staging и commit вместе и доступны через
`ConversionResult::outputArtifacts()`. Имена sidecar по умолчанию содержат
уникальный id поколения. Sidecar-файлы коммитятся до манифеста, а скрытый
inventory удаляет устаревшие sidecar предыдущего управляемого поколения.
Пользовательские HLS patterns сохраняют выбранные имена и не управляются этим
inventory; их манифест всё равно публикуется последним.

### Метаданные и обложка

`AddMetadata` пишет теги уровня контейнера (`-metadata key=value`) и не
требует перекодирования, поэтому комбинируется с `Remux` — перетегирование
идёт stream copy. Ключи из whitelist (`title`, `artist`, `album`,
`album_artist`, `composer`, `genre`, `track`, `disc`, `year`, `date`,
`comment`, `description`, `language`, `copyright`, `publisher`, `encoder`);
неизвестный ключ бросает исключение, а не молча пишется как бессмысленный
тег. Значения — обычные элементы argv: `=`, юникод и переводы строк не
требуют экранирования.

`AddArtwork` встраивает JPEG/PNG как поток `attached_pic`. Раскладку потоков
определяет целевой контейнер, а операция не видит выходной путь, поэтому его
объявляет вызывающий: `AddArtwork::forAudio($image)` для аудио-целей (MP3 по
умолчанию; для M4A/FLAC передайте `id3v2: false` — их muxer'ы отвергают
MP3-специфичный `-id3v2_version`) и `AddArtwork::forVideo($image)` для
видео-целей (MP4/MKV: основное видео остаётся `v:0`, обложка становится
`v:1`). Поток обложки копируется как есть и никогда не перекодируется (JPEG —
это уже MJPEG-поток, PNG остаётся PNG), поэтому операция комбинируется с
`Remux`. В сочетании с видео-`Transcode` добавляйте artwork **после** него —
ffmpeg применяет последнюю подходящую кодек-опцию на поток. Один `AddArtwork`
на pipeline; комбинация с `ExtractAudio` отклоняется (его `-vn` отбросил бы
обложку) — извлекайте аудио *с* обложкой через `Transcode(audioCodec: ...)` +
`forAudio()`:

```php
use Rasuvaeff\MediaConverter\Operation\{AddArtwork, AddMetadata, Remux, Transcode};

// Перетегировать и заменить обложку MP3 без перекодирования:
$pipeline = Pipeline::from('song.mp3')
    ->add(new Remux())
    ->add(new AddMetadata(['title' => 'My Song', 'artist' => 'Me', 'track' => 7]))
    ->add(AddArtwork::forAudio('cover.jpg'));

// Извлечь MP3 из видео И встроить обложку:
$pipeline = Pipeline::from('input.mp4')
    ->add(new Transcode(audioCodec: 'libmp3lame', audioBitrateKbps: 192))
    ->add(AddArtwork::forAudio('cover.jpg'));
```

### Склейка (Concat)

`Pipeline::concat(list<string> $segments, bool $hasAudio = true)` склеивает
несколько сегментов в один вывод, перекодируя через FILTER `concat` ffmpeg
(`-filter_complex … concat=n=…:v=1:a=…`). Это отдельный конструктор pipeline,
а не операция — каждая операция декорирует ОДИН уже существующий основной
источник, а concat собирает несколько сегментов В сам источник:

```php
$pipeline = Pipeline::concat(['part1.mp4', 'part2.mp4', 'part3.mp4'])
    ->add(new Transcode(videoCodec: 'libx264'));
```

`$hasAudio` должен соответствовать каждому сегменту (несовпадение падает
громко на этапе запуска ffmpeg, не молча). Дальнейшие операции всё ещё
комбинируются: codec-only, например `Transcode`, отработает нормально на
склеенном результате, а обычная video- ИЛИ audio-фильтр-операция будет
отклонена — concat ведёт оба потока через `-filter_complex`, который ffmpeg
не совмещает с `-vf`/`-af`, то же правило, что и у `Watermark`.
`Concat` также нельзя скомбинировать с `Watermark` (оба владеют
`-filter_complex`) — pipeline отклоняет это до запуска ffmpeg. Операция,
явно занимающая аудио-выход (`ReplaceAudio` или
`SelectStreams(audioIndex: …)`), отбрасывает аудио-пады сегментов (`a=0`),
так что заявленная дорожка — единственный аудио-поток в выводе. `source()` для
совместимости возвращает первый сегмент, а `sources()` — все сегменты. При
наличии prober каждый сегмент проверяется на DRM, а их длительности суммируются;
если хотя бы одна длительность неизвестна, прогресс остаётся indeterminate.

Это FILTER `concat` (перекодирует), а не lossless ДЕМУКСЕР `concat`
(`-f concat -i list.txt -c copy`, stream-copy, но нужен временный list-файл
со своим жизненным циклом очистки) — отложено; `.m3u8`/`.mpd` плейлисты не
нуждаются ни в том, ни в другом: ffmpeg читает их нативно, поэтому
`Pipeline::from('playlist.m3u8')->add(new Remux())` — это путь remux'а
HLS/DASH-наследия.

### `Position`

Используется `TextOverlay` (и будущими overlay-операциями) для привязки
контента к кадру: `TopLeft`, `TopCenter`, `TopRight`, `MiddleLeft`, `Center`,
`MiddleRight`, `BottomLeft`, `BottomCenter`, `BottomRight`.

### `FfmpegBinary`

| Метод | Описание |
|---|---|
| `new FfmpegBinary(ffmpegPath:, ffprobePath:)` | Свои пути (дефолт `/usr/bin/ffmpeg`, `/usr/bin/ffprobe`) |
| `FfmpegBinary::default()` | Стандартные Unix-пути |
| `ffmpegPath()` / `ffprobePath()` | Аксессоры |
| `assertExecutable()` | Проверяет оба бинарника |
| `assertFfmpegExecutable()` / `assertFfprobeExecutable()` | Проверяет только нужный бинарник; бросает соответствующий reason с exit 127 |

### `ConversionResult` / `MediaInfo`

`ConversionResult` (из движка): `outputPath()`, `outputArtifacts()`, `elapsed()`
(`Duration`), `inputBytes()`, `outputBytes()`, `outputMebibytes()`. Счётчики
байтов включают все concat-входы и все закоммиченные package-артефакты.
Также доступен `command()` с точным argv ffmpeg.

`MediaInfo` (из `ProbesMedia::probe()`, например `FfprobeMediaInfo`):
`duration()` (`Duration`), `width()`, `height()`, `videoCodec()`,
`audioCodec()`, `bitrate()`, `hasVideo()`, `hasAudio()`, `isEncrypted()`.
`isEncrypted()` — эвристика: true, когда вывод ffprobe упоминает "decrypt" —
отдельного флага зашифрованного потока не существует.

### `ConversionFailed`

Наследует `\RuntimeException`; `->reason` — enum `ConversionFailureReason`:

| Reason | Когда |
|---|---|
| `Timeout` | Превышен wall-clock / idle таймаут |
| `NonZeroExit` | ffmpeg завершился ненулём (единственная потенциально транзиентная причина) |
| `NoInput` | Источник не открылся |
| `Drm` | Источник DRM-защищён (зашифрован) |
| `ProbeFailed` | ffprobe не смог прочитать источник |
| `IncompatibleOperations` | Операции pipeline конфликтуют (проверяется до запуска) |
| `OutputFailed` | Не удалось подготовить или закоммитить вывод |
| `FfmpegNotExecutable` / `FfprobeNotExecutable` | Провал preflight или exit code 127 у subprocess |

Также `->exitCode` и `stderrTail()` (никогда не пустой — единственный
читатель захваченного stderr).

### Иерархия исключений

Каждый достижимый провал реализует маркер-интерфейс
`MediaConverterException`, поэтому один `catch` обрабатывает оба случая:

```php
use Rasuvaeff\MediaConverter\MediaConverterException;

try {
    $converter->run($pipeline, 'clip.mp4', token: $token);
} catch (MediaConverterException $e) {
    // ConversionFailed (subprocess/валидация) или ConversionCancelled (token)
}
```

`ConversionFailed` и `ConversionCancelled` оба наследуют `\RuntimeException` и
реализуют маркер. Внутренние `\LogicException` стерегут невозможные состояния
(программные ошибки) и намеренно **не** реализуют его — они должны всплывать
наружу, а не быть проглоченными доменным `catch`.

### `ProgressEvent`

Эмитится движком через `callable(ProgressEvent): void`. Доля выполнения
`fraction()` известна только когда известна общая длительность (из ffprobe);
иначе сэмпл indeterminate — проверяйте `isDeterminate()` перед чтением
`fraction()`. Также несёт `outTime()` (`Duration`), `frame()`, `fps()`,
`speed()`.

### `Preset\Presets`

Готовые `Pipeline` для частых задач — исходный download-кейс (HLS/DASH →
MP4) живёт здесь, как обычная композиция базовых операций, а не зашит в
ядро:

| Пресет | Pipeline |
|---|---|
| `hlsToMp4(source)` | `Remux()` + bitstream-фильтр `aac_adtstoasc` (чинит ADTS AAC-аудио для MP4) |
| `hlsToMp4Transcode(source)` | `Transcode(videoCodec: 'libx264', audioCodec: 'aac')` |
| `dashToMp4(source)` | `Remux()` (fMP4-сегменты DASH не требуют bitstream-фильтра) |
| `webM(source, videoBitrateKbps = 2500, audioBitrateKbps = 128)` | `Transcode(videoCodec: 'libvpx-vp9', audioCodec: 'libopus', …)` — VP9 сжимает лучше VP8, но кодирует медленнее |
| `mp3(source, bitrateKbps = 192)` | `ExtractAudio('libmp3lame', bitrateKbps)` |
| `aac(source, bitrateKbps = 128)` | `ExtractAudio('aac', bitrateKbps)` |
| `webThumbnail(source, Duration $at, width = 320)` | `Thumbnail($at, $width)` |
| `socialClip(source, Duration $from, Duration $to, maxWidth = 720)` | `Trim` + `Scale(width: $maxWidth)` + `Transcode(videoCodec: 'libx264', audioCodec: 'aac')` |

```php
use Rasuvaeff\MediaConverter\Preset\Presets;

$pipeline = Presets::hlsToMp4('https://example.test/stream.m3u8');
$result = $converter->run($pipeline, 'out.mp4');
```

## Безопасность

- Каждый путь и URL — отдельный элемент argv (`symfony/process`), никогда не
  интерполируется в shell-строку.
- `ffmpegPath` / `ffprobePath` хранятся как есть; вызывайте `assertExecutable()`
  до запуска, если пути приходят от пользователя.
- Библиотека не обходит DRM — зашифрованные источники падают с
  `ConversionFailureReason::Drm`.

## Примеры

См. [`examples/`](examples/) — большинство собирают pipeline и печатают его
argv ffmpeg (бинарник не нужен); `cancellation.php` и `cached-probes.php`
прогоняют движок с in-memory заглушками, и лишь `convert.php` выполняет
реальную конвертацию:

- `transcode.php` — ресайз + перекодирование
- `hls-to-mp4.php` — remux HLS-потока (`Preset\Presets::hlsToMp4()`)
- `extract-audio.php` — извлечь MP3-дорожку
- `trim-and-crop.php` — вырезать срез (точный и fast-seek) и кроп квадрата
- `thumbnail.php` — один статичный кадр с ресайзом
- `watermark.php` — наложить изображение-логотип
- `text-overlay.php` — вписать подпись через `drawtext` (учтите требование к шрифту)
- `concat.php` — склеить отдельные файлы-сегменты (`Pipeline::concat()`)
- `metadata.php` — записать теги контейнера при remux
- `artwork.php` — встроить обложку (варианты MP3 и MP4)
- `normalize-loudness.php` — нормализация громкости EBU R128
- `animated-preview.php` — анимированный GIF-превью с оптимизированной палитрой
- `package-hls.php` — упаковка в HLS VOD плейлист + сегменты
- `presets.php` — `Preset\Presets`: превью, социальный клип, извлечение MP3/AAC, WebM
- `cancellation.php` — кооперативная отмена через `CancellationToken`
- `cached-probes.php` — кэширование результатов ffprobe через `CachedProbesMedia`
- `convert.php` — реальная end-to-end конвертация (нужен ffmpeg)

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Полный список команд и конвенций — в `AGENTS.md`.

## Лицензия

BSD-3-Clause, см. [`LICENSE.md`](LICENSE.md).
