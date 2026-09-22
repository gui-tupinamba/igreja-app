import { useCallback, useEffect, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { api, ApiError } from "@/api";
import { Card, Empty, ErrorState, Heading, Loading, Screen, formatDate } from "@/components";
import { enablePushNotifications } from "@/push";
import { colors } from "@/theme";
import type { NotificationItem, NotificationPage, NotificationPreferences } from "@/types";

export default function NotificationsScreen() {
  const params = useLocalSearchParams<{ id?: string }>();
  const [page,setPage]=useState<NotificationPage|null>(null); const [preferences,setPreferences]=useState<NotificationPreferences|null>(null); const [error,setError]=useState<unknown>(); const [busy,setBusy]=useState(false); const [message,setMessage]=useState("");
  const load=useCallback(async()=>{setError(undefined);try{const [items,prefs]=await Promise.all([api<NotificationPage>("/notifications?limit=50&page=1"),api<{preferences:NotificationPreferences}>("/notifications/preferences")]);setPage(items);setPreferences(prefs.preferences);}catch(e){setError(e);}},[]);
  useEffect(()=>{load();},[load]);
  useEffect(()=>{const item=page?.items.find(x=>x.id===Number(params.id));if(item&&!item.read_at)open(item);},[params.id,page]);
  async function open(item:NotificationItem){try{await api(`/notifications/${item.id}/read`,"POST",{});setPage(p=>p?{...p,unread_count:Math.max(0,p.unread_count-(item.read_at?0:1)),items:p.items.map(x=>x.id===item.id?{...x,read_at:new Date().toISOString()}:x)}:p);navigate(item.route);}catch(e){setError(e);}}
  function navigate(route:string|null){if(!route)return;let match;if((match=route.match(/^\/posts\/(\d+)$/)))router.push({pathname:"/(app)/post/[id]",params:{id:match[1]}});else if((match=route.match(/^\/events\/(\d+)$/)))router.push({pathname:"/(app)/event/[id]",params:{id:match[1]}});else if((match=route.match(/^\/ministries\/(\d+)$/)))router.push({pathname:"/(app)/ministry/[id]",params:{id:match[1]}});else if(route==="/agenda")router.push("/(app)/(tabs)/agenda");}
  async function togglePush(value:boolean){if(!preferences)return;setBusy(true);setMessage("");try{if(value)await enablePushNotifications();const response=await api<{preferences:NotificationPreferences}>("/notifications/preferences","PATCH",{push_enabled:value});setPreferences(response.preferences);setMessage(value?"Notificações ativadas.":"Notificações pausadas.");}catch(e){setError(e instanceof Error?e:new ApiError(0,"Não foi possível alterar."));}finally{setBusy(false);}}
  if(!page&&!error)return <Screen><Loading/></Screen>;
  return <Screen><Heading eyebrow="FIQUE POR DENTRO" title="Notificações" subtitle={page?`${page.unread_count} não lida${page.unread_count===1?"":"s"}`:undefined}/>{error?<ErrorState error={error} retry={load}/>:null}{preferences?<Card><View style={styles.row}><View style={styles.grow}><Text style={styles.setting}>Receber notificações push</Text><Text style={styles.muted}>O conteúdo protegido só aparece após abrir o aplicativo.</Text></View><Switch disabled={busy} value={preferences.push_enabled} onValueChange={togglePush} trackColor={{true:colors.green}}/></View>{message?<Text style={styles.success}>{message}</Text>:null}</Card>:null}{page?.items.length?page.items.map(item=><Pressable key={item.id} onPress={()=>open(item)}><Card><View style={styles.titleRow}><Text style={[styles.title,!item.read_at&&styles.unread]}>{item.title}</Text>{!item.read_at?<View style={styles.dot}/>:null}</View><Text style={styles.body}>{item.body}</Text><Text style={styles.date}>{formatDate(item.created_at)}</Text></Card></Pressable>):<Empty>Os avisos da igreja aparecerão aqui.</Empty>}</Screen>;
}
const styles=StyleSheet.create({row:{flexDirection:"row",alignItems:"center",gap:12},grow:{flex:1},setting:{color:colors.ink,fontWeight:"800",fontSize:16},muted:{color:colors.muted,lineHeight:19,marginTop:4},success:{color:colors.green,fontWeight:"700",marginTop:10},titleRow:{flexDirection:"row",alignItems:"center",gap:8},title:{flex:1,color:colors.ink,fontSize:17,fontWeight:"600"},unread:{fontWeight:"900"},dot:{width:9,height:9,borderRadius:5,backgroundColor:colors.gold},body:{color:colors.muted,lineHeight:21,marginTop:7},date:{color:colors.green,fontSize:12,fontWeight:"700",marginTop:12}});
