# Third-Party Notices

AstraeaOS WP includes or is distributed alongside upstream and third-party open-source software. This document provides a high-level map; copyright and license notices inside the actual files remain authoritative.

## WordPress

AstraeaOS WP is based on WordPress. WordPress upstream code is licensed under the GNU General Public License version 2 or, at your option, any later version. The original WordPress license file is retained as `license.txt`.

## Bundled WordPress libraries and components

The upstream WordPress tree includes libraries such as PHPMailer, Requests, SimplePie, sodium_compat and other components, plus default themes/plugins and translations. These retain the licenses and attribution notices shipped with those components.

## Bundled data sets and assets

The embedded GeDefense security kernel ships local data and vector assets. Their provenance is documented in detail in [`astraea-core/GeDefense/data/README.md`](./astraea-core/GeDefense/data/README.md); the essential notices are:

| Artifact | Origin | License |
|---|---|---|
| `astraea-core/GeDefense/data/geoip.csv` — local IP-to-Country / IP-to-ASN database | [iplocate/ip-address-databases](https://github.com/iplocate/ip-address-databases) | **CC BY-SA 4.0** |
| World map vector geometry used by the GeDefense threat map (`world-merc` / `world-map.svg.php`) | [themustafaomar/jsvectormap](https://github.com/themustafaomar/jsvectormap) (v1.7.0) | **MIT** |
| GeDefense handbooks (`*.pdf`), `Sentinel.png`, `Manual.txt` and the GeDefense documentation set | Original VisionGaiaTechnology / GeDefense project material | AstraeaOS/GeDefense project licensing (see `licenses` of the respective component) |

Required attribution for the geo data set — this notice must be preserved in redistributions:

> This product includes IP-to-Country and IP-to-ASN data from the iplocate IP Address Databases project (https://github.com/iplocate/ip-address-databases), licensed under CC BY-SA 4.0.

Note that the geo data set is share-alike licensed: modified versions of that data must remain under CC BY-SA 4.0. This does not change the license of AstraeaOS-specific source code.

## AstraeaOS additions

Original AstraeaOS-specific code is separately identified in `LICENSES/README.md` and is licensed under GNU AGPL v3 unless a file states otherwise.

No AstraeaOS license notice is intended to overwrite a third party's existing license or copyright notice.
