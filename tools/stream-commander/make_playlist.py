#!/usr/bin/env python3
"""Сборка playlist.txt для stream-commander.

Чередует серии двух (или более) сериалов — например, SG-1 и Atlantis, которые
смотрятся параллельно. Файлы внутри каждой папки берутся в алфавитном порядке,
поэтому имена должны содержать номер серии с ведущим нулём (S08E03, а не S08E3).

Примеры:
    # чередование: SG-1 → Atlantis → SG-1 → Atlantis ...
    make_playlist.py "/path/SG-1 S08" "/path/Atlantis S01" > ~/.local/stream-commander/playlist.txt

    # по две серии подряд из каждого
    make_playlist.py --chunk 2 "/path/SG-1 S08" "/path/Atlantis S01"

Проверить результат: wc -l playlist.txt, затем перезапустить демона.
"""

import argparse
import sys
from pathlib import Path

VIDEO_EXT = {".mkv", ".mp4", ".avi", ".m4v"}


def episodes(folder: str) -> list[str]:
    path = Path(folder)
    if not path.is_dir():
        sys.exit(f"Нет такой папки: {folder}")
    # Служебные файлы-двойники macOS («._Имя.mkv») на внешних дисках выглядят как
    # видео, но пустые — без фильтра они удваивают плейлист и дают чёрный экран.
    files = sorted(p for p in path.iterdir()
                   if p.suffix.lower() in VIDEO_EXT and not p.name.startswith("."))
    if not files:
        sys.exit(f"В папке нет видеофайлов: {folder}")
    return [str(p) for p in files]


def interleave(lists: list[list[str]], chunk: int) -> list[str]:
    """Чередование блоками по chunk серий; закончившийся сериал просто выбывает."""
    out, pos = [], [0] * len(lists)
    while any(pos[i] < len(lists[i]) for i in range(len(lists))):
        for i, files in enumerate(lists):
            out.extend(files[pos[i]:pos[i] + chunk])
            pos[i] += chunk
    return out


def main() -> None:
    ap = argparse.ArgumentParser(description="Собрать playlist.txt с чередованием сериалов")
    ap.add_argument("folders", nargs="+", help="папки с сериями, в порядке чередования")
    ap.add_argument("--chunk", type=int, default=1, help="сколько серий подряд из каждого (по умолчанию 1)")
    args = ap.parse_args()

    lists = [episodes(f) for f in args.folders]
    for folder, files in zip(args.folders, lists):
        print(f"# {Path(folder).name}: {len(files)} серий", file=sys.stderr)

    order = interleave(lists, max(1, args.chunk))
    print(f"# всего в плейлисте: {len(order)}", file=sys.stderr)

    print("# playlist.txt для stream-commander — порядок показа сверху вниз")
    for f in order:
        print(f)


if __name__ == "__main__":
    main()
