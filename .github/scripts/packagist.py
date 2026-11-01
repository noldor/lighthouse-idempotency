"""Verify that Packagist indexed the released commit from the GitHub webhook."""

import json
import os
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def request_json(url, *, headers):
    request = Request(url, headers=headers)
    with urlopen(request, timeout=30) as response:
        return json.load(response)


def verify_release(package):
    repository = os.environ['GITHUB_REPOSITORY']
    version = os.environ['RELEASE_TAG'].removeprefix('v')
    commit = os.environ['GITHUB_SHA']
    headers = {'User-Agent': f'{repository} release automation', 'Cache-Control': 'no-cache'}

    for attempt in range(120):
        try:
            metadata = request_json(
                f'https://repo.packagist.org/p2/{package}.json',
                headers=headers,
            )
        except HTTPError as error:
            if error.code != 404:
                raise
            metadata = {}
        except URLError:
            metadata = {}

        for release in metadata.get('packages', {}).get(package, []):
            if release.get('version', '').removeprefix('v') != version:
                continue
            if release.get('source', {}).get('reference') != commit:
                raise RuntimeError('The Packagist version points to another commit.')
            print(f'{package} {version} is visible on Packagist at {commit}.')
            return

        if attempt < 119:
            time.sleep(5)

    raise RuntimeError('The released version is not visible on Packagist; check or redeliver the GitHub webhook and retry the release job.')


if __name__ == '__main__':
    with open('composer.json') as manifest:
        verify_release(json.load(manifest)['name'])
