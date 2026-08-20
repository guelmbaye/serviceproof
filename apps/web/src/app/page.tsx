import { redirect } from "next/navigation";

import { getToken } from "@/lib/session";

export default async function Index() {
  redirect((await getToken()) ? "/overview" : "/login");
}
