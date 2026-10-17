(function(){
    'use strict';
    function start(){
        var root=document.getElementById('llm-usage');if(!root)return;
        var rates=root.querySelector('#usage-rates'),status=root.querySelector('#usage-status');
        var labels={chat:'Разговор',mention:'Упоминания',greeting:'Приветствия',cron_spontaneous:'Спонтанные реплики',utility:'Служебные запросы',memory:'Память',vision:'Зрение',image:'Изображения',announcement:'Анонсы',synopsis:'Резюме серий',search:'Поиск',other:'Другие'};
        var commands={text:'текстовый ответ',schedule:'расписание',poll:'опрос',todo:'беклог',image:'рисунок',image_chat:'рисунок чата',memory_add:'запомнить',memory_show:'показать память',memory_forget:'забыть',reminder:'напоминание',recap:'пересказ',oc_set:'персонаж',ban:'бан',mute:'мут',unban:'разбан',playlist:'плейлист',attendance:'перерыв',command_interaction_reply:'продолжение диалога'};
        function cell(row,text){var td=document.createElement('td');td.textContent=text;row.appendChild(td);return td;}
        function number(value){return value===null?'—':Number(value).toLocaleString('ru-RU');}
        function addRate(rate){
            var tr=document.createElement('tr');
            ['provider','model','currency','input','output','request'].forEach(function(field){
                var td=cell(tr,'');var input=document.createElement(field==='provider'||field==='currency'?'select':'input');input.className='form-input';input.dataset.field=field;
                if(field==='provider'||field==='currency'){
                    (field==='provider'?['routerai','openai','openrouter','yandexgpt','gigachat','cli']:['USD','RUB','EUR']).forEach(function(value){var option=document.createElement('option');option.value=value;option.textContent=value;input.appendChild(option);});
                    input.value=rate[field]||(field==='provider'?'routerai':'USD');
                }else{input.type=field==='model'?'text':'number';input.value=rate[field]??'';input.required=field==='model';if(field==='model')input.maxLength=190;else{input.min='0';input.max='1000000';input.step='any';}}
                input.setAttribute('aria-label',({provider:'Провайдер',model:'Модель',currency:'Валюта',input:'Цена ввода',output:'Цена вывода',request:'Цена изображения'})[field]);td.appendChild(input);
            });
            var button=document.createElement('button');button.type='button';button.className='btn-secondary';button.textContent='Удалить';button.onclick=function(){dirty=true;tr.remove();};cell(tr,'').appendChild(button);rates.appendChild(tr);
        }
        var generation=0,dirty=false;
        rates.addEventListener('input',function(){dirty=true;});rates.addEventListener('change',function(){dirty=true;});
        root.querySelector('#usage-add-rate').onclick=function(){dirty=true;addRate({});};
        async function load(){
            var token=++generation;status.textContent='Загрузка…';
            try{
                var res=await fetch('/api.php',{method:'POST',body:new URLSearchParams({action:'get_llm_usage',days:root.querySelector('#usage-days').value,csrf_token:window.csrfToken||''})});
                var out=await res.json();if(token!==generation)return;if(!out.success)throw Error(out.message);
                var data=out.data,body=root.querySelector('#usage-rows');body.replaceChildren();var attempts=0,unknown=0,currencies={};
                data.rows.forEach(function(item){
                    attempts+=Number(item.attempts);unknown+=Number(item.unknown_cost);
                    var row=document.createElement('tr');var model=cell(row,item.provider);var small=document.createElement('small');small.className='usage-model';small.textContent=item.model;model.appendChild(small);
                    cell(row,labels[item.task]||(item.task.startsWith('command_')?'Команда: '+(commands[item.task.slice(8)]||'другая'):'Другие'));cell(row,number(item.attempts));cell(row,number(item.errors)+' / '+number(item.truncated));var tokens=cell(row,number(item.input_tokens)+' / '+number(item.output_tokens));var total=document.createElement('small');total.className='usage-model';total.textContent='Всего: '+number(item.total_tokens);tokens.appendChild(total);cell(row,number(item.unknown_usage));
                    cell(row,item.estimated_cost===null?'Нет тарифа или данных':Number(item.estimated_cost).toLocaleString('ru-RU',{maximumFractionDigits:8})+' '+item.currency+(Number(item.unknown_cost)?' · частично':''));body.appendChild(row);
                    if(item.estimated_cost!==null)currencies[item.currency]=(currencies[item.currency]||0)+Number(item.estimated_cost);
                });
                if(!data.rows.length){var row=document.createElement('tr');cell(row,'Запросов за этот период пока нет.').colSpan=7;body.appendChild(row);}
                var totals=root.querySelector('#usage-totals');totals.replaceChildren();
                ['Попыток: '+number(attempts),'Без оценки стоимости: '+number(unknown)].concat(Object.keys(currencies).map(function(currency){return 'Учтённая оценка: '+currencies[currency].toLocaleString('ru-RU',{maximumFractionDigits:8})+' '+currency;})).forEach(function(text){var span=document.createElement('span');span.className='usage-total';span.textContent=text;totals.appendChild(span);});
                if(!dirty){rates.replaceChildren();data.rates.forEach(addRate);var known=new Set(data.rates.map(function(r){return r.provider+'|'+r.model;}));data.rows.forEach(function(r){var key=r.provider+'|'+r.model;if(!known.has(key)&&rates.children.length<100){known.add(key);addRate({provider:r.provider,model:r.model});}});}
                status.textContent='За последние '+data.days+' дней. «—» означает, что провайдер не сообщил показатель.';
            }catch(error){if(token===generation)status.textContent=error.message||'Не удалось загрузить потребление.';}
        }
        root.querySelector('#usage-days').onchange=load;root.querySelector('#usage-refresh').onclick=load;
        root.querySelector('#usage-rates-form').onsubmit=async function(event){
            event.preventDefault();var button=event.submitter||event.currentTarget.querySelector('[type=submit]');button.disabled=true;
            var rows=Array.from(rates.children).map(function(row){var rate={};row.querySelectorAll('[data-field]').forEach(function(input){rate[input.dataset.field]=input.value;});return rate;});
            try{var res=await fetch('/api.php',{method:'POST',body:new URLSearchParams({action:'save_llm_usage_rates',rates:JSON.stringify(rows),csrf_token:window.csrfToken||''})});var out=await res.json();if(!out.success)throw Error(out.message);dirty=false;if(window.showFlashMessage)window.showFlashMessage(out.message,'success');await load();}
            catch(error){if(window.showFlashMessage)window.showFlashMessage(error.message,'error');else status.textContent=error.message;}finally{button.disabled=false;}
        };
        load();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
