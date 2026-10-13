"""Fetch public data on the server so visitors use only same-origin JSON."""
import concurrent.futures
import datetime as dt
import json
import math
from pathlib import Path
import urllib.request
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
CITIES = [('obninsk','Обнинск',55.0968,36.6101),('moscow','Москва',55.7558,37.6176),('spb','Санкт-Петербург',59.9343,30.3351),('kazan','Казань',55.7961,49.1064),('ekb','Екатеринбург',56.8389,60.6057),('novosibirsk','Новосибирск',55.0084,82.9357),('krasnodar','Краснодар',45.0355,38.9753),('vladivostok','Владивосток',43.1155,131.8855)]
def fetch(url):
    request=urllib.request.Request(url,headers={'User-Agent':'MixlabStudentSite/1.0'})
    with urllib.request.urlopen(request,timeout=25) as response:
        return response.read()
def write(name,value):
    destination=ROOT/'data'/name
    temp=destination.with_suffix('.tmp')
    temp.write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
    temp.replace(destination)
def previous(name):
    try:return json.loads((ROOT/'data'/name).read_text())
    except (OSError,ValueError):return {}
def now():return dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds')
def city_weather(city):
    key,name,lat,lon=city
    url=f'https://api.open-meteo.com/v1/forecast?latitude={lat}&longitude={lon}&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m&wind_speed_unit=ms&timezone=GMT'
    current=json.loads(fetch(url))['current']
    for field in ['temperature_2m','apparent_temperature','wind_speed_10m']:
        if not isinstance(current[field],(int,float)) or not math.isfinite(current[field]):raise ValueError('Invalid weather value')
    return key,dict(name=name,temperature=current['temperature_2m'],feels_like=current['apparent_temperature'],wind=current['wind_speed_10m'],code=current['weather_code'],observed_at=current['time']+'Z',updated_at=now())
def weather():
    cities=previous('weather.json').get('cities',{})
    success=0
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        jobs={pool.submit(city_weather,c):c[0] for c in CITIES}
        for job in concurrent.futures.as_completed(jobs):
            try:key,value=job.result();cities[key]=value;success+=1
            except Exception as error:print('Weather update failed:',jobs[job],type(error).__name__)
    if not success:raise RuntimeError('No fresh weather received; retained previous snapshot')
    write('weather.json',dict(source='Open-Meteo',source_url='https://open-meteo.com/',license='CC BY 4.0',updated_at=now(),cities=cities))
def currency():
    root=ET.fromstring(fetch('https://www.cbr.ru/scripts/XML_daily.asp'))
    values={}
    for row in root.findall('Valute'):
        code=row.findtext('CharCode')
        if code in ('USD','EUR','CNY'):
            value=float(row.findtext('Value').replace(',','.'))/int(row.findtext('Nominal'))
            if not math.isfinite(value) or value<=0:raise ValueError('Invalid currency value')
            values[code]=value
    if len(values)!=3:raise ValueError('Incomplete currencies')
    effective=dt.datetime.strptime(root.attrib['Date'],'%d.%m.%Y').date().isoformat()
    write('currency.json',dict(source='Банк России',source_url='https://www.cbr.ru/currency_base/daily/',effective_date=effective,updated_at=now(),rates=values))
if __name__=='__main__':
    failures=[]
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        jobs={pool.submit(fn):fn.__name__ for fn in (weather,currency)}
        for job in concurrent.futures.as_completed(jobs):
            try:job.result();print(jobs[job]+': updated')
            except Exception as error:failures.append(jobs[job]);print(jobs[job]+': '+str(error))
    if failures:raise SystemExit('Refresh failed: '+', '.join(failures))
