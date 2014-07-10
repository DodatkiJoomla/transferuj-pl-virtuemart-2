function ShowChannels()
{
    var str='<div id="kanaly"><h3>Wybierz kanał płatności z poniższej listy:</h3>';

        for( var i=0; i<tr_channels.length; i++ )
        {
            var id = 'bank'+tr_channels[i][0];
            var width_style = '';
            var idi = 'i_'+id;
            if(tr_channels[i][0] == 40) width_style = 'width:270px'; else width_style = '';

            str +='<div id="'+id+'" onclick=\'document.getElementById("'+idi+'").checked=true;document.forms["platnosc_transferuj"].submit();\' style="background-position:center;background-image:url('+tr_channels[i][3]+');'+width_style+'">';
            str +='<input id="'+idi+'" type="radio" value="'+tr_channels[i][0]+'" name="kanal" '; if (i==0) str +='checked="checked"  />'; else str += ' />';
            str += '<p class="label">'+
            '<label for="'+idi+'">'+tr_channels[i][1]+'</label>'+
            '</p>'+
            '</div>';

        }

        str+="</div>";

        document.getElementById('transferuj_content').innerHTML=str;
}
