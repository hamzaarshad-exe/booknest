"""Runs only against the workflow's disposable localhost containers."""
import urllib.request, urllib.parse, urllib.error, http.cookiejar, re

origin='http://localhost:18080'
cookies=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
with client.open(origin+'/',timeout=15) as response:
    public=response.read().decode()
    assert response.status==200 and 'Local Test Business' in public
with client.open(origin+'/index.php?page=login',timeout=15) as response:
    html=response.read().decode()
token=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
body=urllib.parse.urlencode({'action':'login','csrf':token,'email':'owner@example.test','password':'ci-disposable-owner-password'}).encode()
with client.open(origin+'/index.php',body,timeout=15) as response:
    html=response.read().decode()
    assert response.status==200 and 'Coming up next' in html and 'Workspace' in html
for page in ['services','staff','schedule','settings','appointments']:
    with client.open(origin+'/index.php?page='+page,timeout=15) as response:
        assert response.status==200 and 'temporarily unavailable' not in response.read().decode()
try:
    client.open(origin+'/app/schema.php',timeout=10)
except urllib.error.HTTPError as error:
    assert error.code==403
else:
    raise AssertionError('Apache must deny the private app directory')
print('Container checks passed: startup, owner login, dashboard, management pages, private directory protection.')
