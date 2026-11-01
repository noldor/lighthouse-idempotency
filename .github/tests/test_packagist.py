import os
import sys
import unittest
import io
import json
from pathlib import Path
from urllib.error import HTTPError, URLError
from unittest.mock import call, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import packagist


class PackagistVerificationTest(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {
            'GITHUB_REPOSITORY': 'noldor/lighthouse-idempotency',
            'GITHUB_SHA': 'a' * 40,
            'RELEASE_TAG': 'v0.1.0',
        }, clear=True)
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def metadata(self, reference='a' * 40):
        return {'packages': {'noldors/lighthouse-idempotency': [{
            'version': 'v0.1.0', 'source': {'reference': reference},
        }]}}

    @patch.object(packagist, 'urlopen')
    def testVerifiesPublishedCommitWithoutCredentialsOrRefresh(self, open_url):
        open_url.return_value.__enter__.return_value = io.BytesIO(json.dumps(self.metadata()).encode())
        packagist.verify_release('noldors/lighthouse-idempotency')
        open_url.assert_called_once()
        request = open_url.call_args.args[0]
        self.assertEqual(request.full_url, 'https://repo.packagist.org/p2/noldors/lighthouse-idempotency.json')
        self.assertEqual(request.get_method(), 'GET')
        self.assertIsNone(request.get_header('Authorization'))
        self.assertIsNone(request.data)

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testWaitsForTheQueuedUpdate(self, request, sleep):
        request.side_effect = [{'packages': {}}, self.metadata()]
        packagist.verify_release('noldors/lighthouse-idempotency')
        sleep.assert_called_once_with(5)

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testWaitsForInitialMetadataToBecomeAvailable(self, request, sleep):
        request.side_effect = [
            HTTPError('https://repo.packagist.org', 404, 'Not found', None, None),
            self.metadata(),
        ]
        packagist.verify_release('noldors/lighthouse-idempotency')
        sleep.assert_called_once_with(5)

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testRetriesATransientConnectionFailure(self, request, sleep):
        request.side_effect = [URLError('Temporary connection failure'), self.metadata()]
        packagist.verify_release('noldors/lighthouse-idempotency')
        sleep.assert_called_once_with(5)

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testRejectsNonTransientHttpErrors(self, request, sleep):
        request.side_effect = HTTPError('https://repo.packagist.org', 403, 'Forbidden', None, None)
        with self.assertRaises(HTTPError):
            packagist.verify_release('noldors/lighthouse-idempotency')
        self.assertEqual(request.call_count, 1)
        sleep.assert_not_called()

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testRejectsAVersionPointingAtAnotherCommit(self, request, sleep):
        request.return_value = self.metadata('b' * 40)
        with self.assertRaisesRegex(RuntimeError, 'another commit'):
            packagist.verify_release('noldors/lighthouse-idempotency')
        self.assertEqual(request.call_count, 1)
        sleep.assert_not_called()

    @patch.object(packagist.time, 'sleep')
    @patch.object(packagist, 'request_json')
    def testFailsWhenTheVersionNeverAppears(self, request, sleep):
        request.return_value = {'packages': {}}
        with self.assertRaisesRegex(RuntimeError, 'not visible'):
            packagist.verify_release('noldors/lighthouse-idempotency')
        self.assertEqual(request.call_count, 120)
        self.assertEqual(sleep.call_args_list, [call(5)] * 119)


class PackagistRequestTest(unittest.TestCase):
    @patch.object(packagist, 'urlopen')
    def testReadsMetadataWithAGetRequest(self, open_url):
        open_url.return_value.__enter__.return_value = io.BytesIO(b'{"packages":{}}')
        result = packagist.request_json(
            'https://repo.packagist.org/p2/noldors/lighthouse-idempotency.json',
            headers={'User-Agent': 'Release verification', 'Cache-Control': 'no-cache'},
        )
        open_url.assert_called_once()
        request = open_url.call_args.args[0]
        self.assertEqual(request.full_url, 'https://repo.packagist.org/p2/noldors/lighthouse-idempotency.json')
        self.assertEqual(request.get_method(), 'GET')
        self.assertEqual(request.get_header('User-agent'), 'Release verification')
        self.assertEqual(request.get_header('Cache-control'), 'no-cache')
        self.assertIsNone(request.get_header('Authorization'))
        self.assertIsNone(request.data)
        self.assertEqual(open_url.call_args.kwargs, {'timeout': 30})
        self.assertEqual(result, {'packages': {}})


if __name__ == '__main__':
    unittest.main()
