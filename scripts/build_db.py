#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""厚労省の訪問看護 CSV から、画面が読む SQLite を作る。

  /usr/bin/python3 scripts/build_db.py data/jigyosho_130_all_20260709180342.csv
  → php/khokan_data/khokan.sqlite

元データ: 介護サービス情報公表システム オープンデータ「130_訪問看護」
  https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html
  公共データ利用規約(PDL1.0)。出典と「加工して作成」の表示が必須（画面の脚注で出す）。
  提供時点は 6月末・12月末。ファイル名の日付は「提供日」で、データ時点ではない。

ここでやること（画面で数字を作らない。全部ここで数える）:
  - 24列をそのまま持つ（列名は元のまま。勝手に意味を変えない）
  - name_core: 「訪問看護ステーション」「株式会社」などを除いた名前の芯。
    同名の別ステーション（ひまわり49軒・さくら48軒…）を「これではなくこちら」と
    切り分けるために使う。名前検索で着地した人の最初の疑問はそこ。
  - 曜日フラグ: 利用可能曜日 から 平日/土/日/祝 を分ける
  - meta: データ時点・取り込み日・件数・出典

このCSVに無いもの: 24時間対応・精神科・従業者数・利用者数・空き状況。
無いものは画面でも出さない（別ソースを足すときは列を足し、出典を書く）。
"""
import csv
import datetime as dt
import io
import os
import re
import sqlite3
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT_DIR = os.path.join(ROOT, "php", "khokan_data")
OUT = os.path.join(OUT_DIR, "khokan.sqlite")
SRC_URL = "https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html"

STRIP = re.compile(
    r"(訪問看護リハビリステーション|訪問看護ステーション|訪問看護ステーシヨン|訪問看護|ステーション"
    r"|株式会社|合同会社|有限会社|合資会社|医療法人社団|医療法人財団|医療法人|社会福祉法人|一般社団法人"
    r"|一般財団法人|公益社団法人|公益財団法人|特定非営利活動法人|ＮＰＯ法人|NPO法人|生活協同組合)")
PUNCT = re.compile(r"[\s　・（）()「」『』\[\]【】〔〕\-－‐–—~〜～/／,，.．]")


def name_core(name: str) -> str:
    s = STRIP.sub("", name)
    s = PUNCT.sub("", s)
    return s.strip()


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    src = sys.argv[1]
    raw = open(src, "rb").read()
    text = raw.decode("utf-8-sig")
    rows = list(csv.DictReader(io.StringIO(text)))
    if not rows:
        print("CSV が空", file=sys.stderr)
        return 1
    # 提供日はファイル名から。データ時点は厚労省ページの「6月末/12月末」に従い、提供日の直前の末日。
    m = re.search(r"_(\d{4})(\d{2})(\d{2})\d*\.csv$", os.path.basename(src))
    provided = dt.date(int(m.group(1)), int(m.group(2)), int(m.group(3))) if m else dt.date.today()
    vintage = dt.date(provided.year, 6, 30) if provided.month >= 7 else dt.date(provided.year - 1, 12, 31)

    os.makedirs(OUT_DIR, exist_ok=True)
    tmp = OUT + ".tmp"
    if os.path.exists(tmp):
        os.remove(tmp)
    con = sqlite3.connect(tmp)
    con.executescript("""
    CREATE TABLE stations (
      no TEXT PRIMARY KEY,            -- 事業所番号
      pref_code TEXT, pref TEXT, city TEXT, city_code TEXT,
      name TEXT, name_kana TEXT, name_core TEXT,
      address TEXT, address2 TEXT, lat REAL, lon REAL,
      tel TEXT, fax TEXT, corp_no TEXT, corp TEXT,
      days TEXT, days_note TEXT,
      weekday INTEGER, sat INTEGER, sun INTEGER, holiday INTEGER,
      capacity TEXT, url TEXT,
      both_use TEXT, kaigo_std TEXT, shogai_std TEXT, note TEXT
    );
    CREATE INDEX ix_core ON stations(name_core);
    CREATE INDEX ix_city ON stations(pref_code, city);
    CREATE INDEX ix_lat ON stations(lat);
    CREATE INDEX ix_lon ON stations(lon);
    CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
    """)
    ins = ("INSERT OR REPLACE INTO stations VALUES (" + ",".join("?" * 28) + ")")
    n = 0
    for r in rows:
        days = r.get("利用可能曜日", "") or ""
        rec = (
            r["事業所番号"].strip(),
            r["都道府県コード又は市町村コード"][:2], r["都道府県名"].strip(), r["市区町村名"].strip(),
            r["都道府県コード又は市町村コード"].strip(),
            r["事業所名"].strip(), r["事業所名カナ"].strip(), name_core(r["事業所名"]),
            r["住所"].strip(), r["方書（ビル名等）"].strip(),
            float(r["緯度"]) if r["緯度"].strip() else None,
            float(r["経度"]) if r["経度"].strip() else None,
            r["電話番号"].strip(), r["FAX番号"].strip(), r["法人番号"].strip(), r["法人の名称"].strip(),
            days, r.get("利用可能曜日特記事項", "").strip(),
            int("平日" in days), int("土曜" in days), int("日曜" in days), int("祝日" in days),
            r["定員"].strip(), r["URL"].strip(),
            r["高齢者の方と障害者の方が同時一体的に利用できるサービス"].strip(),
            r["介護保険の通常の指定基準を満たしている"].strip(),
            r["障害福祉の通常の指定基準を満たしている"].strip(),
            r["備考"].strip(),
        )
        con.execute(ins, rec)
        n += 1
    con.executemany("INSERT INTO meta VALUES (?,?)", [
        ("source_url", SRC_URL),
        ("source_name", "介護サービス情報公表システム オープンデータ（130_訪問看護）／厚生労働省"),
        ("source_file", os.path.basename(src)),
        ("license", "公共データ利用規約（第1.0版）。出典表示と、加工して作成した旨の表示が必要"),
        ("data_vintage", vintage.isoformat()),
        ("provided_on", provided.isoformat()),
        ("built_at", dt.datetime.now().strftime("%Y-%m-%dT%H:%M:%S")),
        ("count", str(n)),
    ])
    con.commit()
    # 集計を画面が毎回やらないように、ここで数える
    cores = con.execute("SELECT COUNT(*) FROM (SELECT name_core FROM stations WHERE name_core<>'' GROUP BY name_core HAVING COUNT(*)>=2)").fetchone()[0]
    shared = con.execute("SELECT COUNT(*) FROM stations WHERE name_core IN (SELECT name_core FROM stations WHERE name_core<>'' GROUP BY name_core HAVING COUNT(*)>=2)").fetchone()[0]
    con.executemany("INSERT OR REPLACE INTO meta VALUES (?,?)", [
        ("shared_names", str(cores)), ("shared_stations", str(shared)),
        ("with_url", str(con.execute("SELECT COUNT(*) FROM stations WHERE url<>''").fetchone()[0])),
        ("sun_open", str(con.execute("SELECT COUNT(*) FROM stations WHERE sun=1").fetchone()[0])),
    ])
    con.commit()
    con.close()
    os.replace(tmp, OUT)
    print(f"{n:,}件 → {OUT}  データ時点 {vintage}  提供日 {provided}")
    print(f"  同名グループ {cores:,} / それに属する事業所 {shared:,}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
