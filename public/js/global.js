var global={init:function(){$("[rel=tooltip]").tooltip();$("#buymeabeer").click(function(a){a.preventDefault();$("#buymeabeer_button").trigger("click")});$(document).on("click",".add-to-list",function(){var b=$(this).data("itemId"),a=$(this).data("itemName");$.ajax({url:"/list/add",type:"post",data:{"item-id":b},beforeSend:function(){noty({text:"Adding "+a+" to your list",type:"success",layout:"bottomRight",timeout:2500})}})})},notification:function(a,b,c){$("#notifications").append('<div class="alert alert-'+a+'" id="'+c+'">'+b)},fade_and_destroy:function(a){a.fadeOut(500,function(){a.remove()})}};$(global.init);